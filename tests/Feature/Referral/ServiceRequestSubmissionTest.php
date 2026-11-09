<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\CompositionErrorTranslator;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\TestCase;

class ServiceRequestSubmissionTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;

    private function context(): array
    {
        $context = $this->compositionFixture(scopes: ['service_request:write', 'service_request:cancel']);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($resource['subject']);
        $repository = app(ServiceRequestRequestRepository::class);
        $record = $repository->storeResource($resource, $context['person']->id);
        Notification::fake();
        Bus::fake();

        return [$repository, $record, $context['user'], $context['person']];
    }

    public function test_failed_receipt_uses_its_api_translator_and_preserves_the_resource(): void
    {
        [$repository, $record] = $this->context();
        $before = $repository->getResource($record);
        $response = new EHealthResponse(
            new Response(200, [], json_encode(['data' => [
                'status' => 'failed', 'error' => ['message' => 'Same end date for new and existing composition'],
            ]], JSON_THROW_ON_ERROR)),
            Job::validateSubmissionResponse(...),
            errorTranslator: new CompositionErrorTranslator()
        );

        // The supplied API response owns the translator, independently of the submitted model type.
        try {
            RemoteEHealthLinksProcessing::submitOperation($record, ['status' => 'entered_in_error'], fn () => $response);
            $this->fail('The rejected receipt must not be treated as a successful submission.');
        } catch (EHealthValidationException $exception) {
            $this->assertStringContainsString(__('errors.ehealth.rules.1103'), $exception->getFormattedMessage());
        }
        $this->assertSame($before, $repository->getResource($record->fresh()));
        $this->assertSame(JobStatus::FAILED->value, $record->ehealthLinks()->firstOrFail()->status);
    }

    public function test_pending_cancel_is_queued_and_duplicate_send_and_edit_are_blocked(): void
    {
        [$repository, $record] = $this->context();
        $before = $repository->getResource($record);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'pending', 'job_id' => 'synthetic-job']);
        $this->assertNull(RemoteEHealthLinksProcessing::submitOperation($record, ['status' => 'entered_in_error'], fn () => $response, 'service_request:cancel'));
        $this->assertSame($before, $repository->getResource($record));
        $link = $record->ehealthLinks()->firstOrFail();
        $this->assertSame('/api/jobs/synthetic-job', $link->href);
        $this->assertSame('service_request:cancel', $link->job->request_data['requiredScope']);
        $this->assertFalse($repository->toPatientRegistryRow($record)['canCancel']);
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->first() instanceof RemoteEHealthLinksProcessing);
        foreach (['submit', 'edit'] as $operation) {
            try {
                $operation === 'submit'
                    ? RemoteEHealthLinksProcessing::submitOperation($record, ['status' => 'entered_in_error'], fn () => throw new \RuntimeException('Duplicate RPC'))
                    : $repository->store($repository->getResource($record), $record->personId);
                $this->fail('Pending operations must block resubmission and edits.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('submission', $exception->errors());
            }
        }
        Notification::assertNothingSent();
    }

    public function test_signed_lifecycle_recall_keeps_local_status_pending_until_the_job_finishes(): void
    {
        [$repository, $record, $user, $person] = $this->context();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('recall')->once()->with($person->uuid, $record->uuid, ['signed_data' => 'synthetic-signature', 'signed_data_encoding' => 'base64'])
            ->andReturn(new EHealthResponse(new \GuzzleHttp\Psr7\Response(202, [], json_encode(['data' => ['job_id' => 'synthetic-recall']], JSON_THROW_ON_ERROR)), \App\Classes\eHealth\Api\Job::validateSubmissionResponse(...)));
        $this->instance(ServiceRequestApi::class, $api);
        $result = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->recallReferral($person->uuid, $record->uuid, [
            'signed_data' => 'synthetic-signature',
            'explanatory_letter' => 'Synthetic explanation',
        ]);
        $this->assertTrue($result['submissionPending']);
        $this->assertSame('active', $record->fresh()->status);
        $link = $record->ehealthLinks()->firstOrFail();
        $this->assertSame('/api/jobs/synthetic-recall', $link->href);
        $this->assertSame('service_request:recall', $link->job->request_data['requiredScope']);
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->first() instanceof RemoteEHealthLinksProcessing);
        RemoteEHealthLinksProcessing::finishSubmission($link, ['status' => 'processed', 'result' => ['id' => $record->uuid, 'status' => 'recalled']], $user);
        $this->assertSame('recalled', $record->fresh()->status);
        $this->assertFalse($repository->hasPendingSubmission($record));
    }

    public function test_release_keeps_local_usage_until_processed_and_resets_program_progress(): void
    {
        [$repository, $record, $user, $person] = $this->context();
        $resource = $repository->getResource($record);
        $resource['programProcessingStatus'] = 'in_progress';
        $record = $repository->storeResource($resource, $person->id);
        $repository->saveStatus($record, 'in_progress');
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('cancelUsage')->once()->with($record->uuid, $person->uuid)
            ->andReturn(new EHealthResponse(new \GuzzleHttp\Psr7\Response(202, [], json_encode(['data' => ['job_id' => 'release-job']], JSON_THROW_ON_ERROR)), \App\Classes\eHealth\Api\Job::validateSubmissionResponse(...)));
        $this->instance(ServiceRequestApi::class, $api);
        $result = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->cancelUsage($record->uuid, $person->uuid, ['explanatory_letter' => 'Synthetic explanation']);
        $this->assertTrue($result['submissionPending']);
        $this->assertSame('in_progress', $record->fresh()->status);
        $link = $record->ehealthLinks()->firstOrFail();
        $this->assertSame('service_request:use', $link->job->request_data['requiredScope']);
        $this->assertSame('Synthetic explanation', $link->job->request_data['operationContext']['releaseExplanation']);
        RemoteEHealthLinksProcessing::finishSubmission($link, ['status' => 'processed', 'result' => ['id' => $record->uuid, 'status' => 'active']], $user);
        $this->assertSame('active', $record->fresh()->status);
        $this->assertSame('new', $repository->getResource($record->fresh())['programProcessingStatus']);
        $this->assertFalse($repository->hasPendingSubmission($record));
    }

    public function test_failed_release_preserves_local_usage_and_program_progress(): void
    {
        [$repository, $record, $user, $person] = $this->context();
        $resource = $repository->getResource($record);
        $resource['programProcessingStatus'] = 'in_progress';
        $record = $repository->storeResource($resource, $person->id);
        $repository->saveStatus($record, 'in_progress');
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('cancelUsage')->once()->with($record->uuid, $person->uuid)
            ->andReturn(new EHealthResponse(new \GuzzleHttp\Psr7\Response(202, [], json_encode(['data' => ['job_id' => 'failed-release']], JSON_THROW_ON_ERROR)), \App\Classes\eHealth\Api\Job::validateSubmissionResponse(...)));
        $this->instance(ServiceRequestApi::class, $api);
        app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->cancelUsage($record->uuid, $person->uuid);
        $link = $record->ehealthLinks()->firstOrFail();
        RemoteEHealthLinksProcessing::finishSubmission($link, ['status' => 'failed', 'error' => ['message' => 'Synthetic rejection']], $user);
        $this->assertSame('in_progress', $record->fresh()->status);
        $this->assertSame('in_progress', $repository->getResource($record->fresh())['programProcessingStatus']);
        $this->assertSame(JobStatus::FAILED->value, $link->fresh()->status);
        $this->assertFalse($repository->hasPendingSubmission($record));
    }

    public function test_processed_cancel_applies_full_reason_and_receipt_once(): void
    {
        [$repository, $record, $user] = $this->context();
        $reason = ['coding' => [['system' => 'eHealth/service_request_cancel_reasons', 'code' => 'synthetic-reason']], 'text' => 'Synthetic explanation'];
        $link = $repository->beginSubmission($record, ['status' => 'entered_in_error', 'statusReason' => $reason], 'service_request:cancel');
        $result = ['status' => 'processed', 'result' => [['id' => $record->uuid, 'status' => 'entered_in_error']]];
        RemoteEHealthLinksProcessing::finishSubmission($link, $result, $user);
        $this->assertSame('entered_in_error', $record->fresh()->status);
        $this->assertSame($reason, $repository->getResource($record->fresh())['statusReason']);
        $this->assertSame(JobStatus::PROCESSED->value, $link->fresh()->status);
        RemoteEHealthLinksProcessing::finishSubmission($link->fresh(), $result, $user);
        Notification::assertSentToTimes($user, \App\Notifications\RemoteEHealthLinksNotification::class, 1);
    }

    public function test_failed_or_mismatched_result_preserves_the_submitted_resource(): void
    {
        [$repository, $record, $user] = $this->context();
        $before = $repository->getResource($record);
        $link = $repository->beginSubmission($record, ['status' => 'recalled'], 'service_request:recall');
        try {
            $repository->finishSubmission($link, ['status' => 'processed', 'result' => ['id' => (string) Str::uuid(), 'status' => 'recalled']]);
            $this->fail('A different identity must not finish the receipt.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue($repository->hasPendingSubmission($record));
        }
        $this->assertSame($before, $repository->getResource($record));
        RemoteEHealthLinksProcessing::finishSubmission($link, ['status' => 'failed', 'error' => ['message' => 'Synthetic rejection']], $user);
        $this->assertSame($before, $repository->getResource($record));
        $this->assertFalse($repository->hasPendingSubmission($record));
        $this->assertNotSame($link->id, $repository->beginSubmission($record, ['status' => 'recalled'])->id);
    }

    public function test_confirmed_receipt_survives_a_detail_transport_failure(): void
    {
        [$repository, $record, $user, $person] = $this->context();
        $link = $repository->beginSubmission($record, ['status' => 'recalled'], 'service_request:recall');
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getById')->once()->with($person->uuid, $record->uuid)->andThrow(new EHealthConnectionException('Synthetic timeout'));
        $this->instance(ServiceRequestApi::class, $api);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'processed', 'status_code' => 200, 'links' => [['entity' => 'service_request', 'href' => '/service_requests/'.$record->uuid]]]);
        $job = new ServiceRequestProcessingHarness($link, legalEntity(), standalone: true);
        $job->user = $user;
        $job->processConfirmed($response);
        $this->assertSame('recalled', $record->fresh()->status);
        $this->assertFalse($repository->hasPendingSubmission($record));
        $this->assertSame(JobStatus::PROCESSED->value, $link->fresh()->status);
        $this->assertTrue($link->job->fresh()->response_data['detailSyncPending']);
        Notification::assertSentToTimes($user, \App\Notifications\RemoteEHealthLinksNotification::class, 2);
    }

    public function test_the_job_link_is_found_even_when_a_resource_link_comes_first(): void
    {
        [$repository, $record] = $this->context();
        $response = new EHealthResponse(new \GuzzleHttp\Psr7\Response(202, [], json_encode(['data' => ['links' => [['entity' => 'service_request', 'href' => '/service_requests/'.$record->uuid], ['entity' => 'job', 'href' => '/Jobs/synthetic-job']]]], JSON_THROW_ON_ERROR)), \App\Classes\eHealth\Api\Job::validateSubmissionResponse(...));
        $this->assertNull(RemoteEHealthLinksProcessing::submitOperation($record, ['status' => 'recalled'], fn () => $response));
        $this->assertSame('/Jobs/synthetic-job', $record->ehealthLinks()->firstOrFail()->href);
        $this->assertSame('active', $record->fresh()->status);
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->first() instanceof RemoteEHealthLinksProcessing);
    }

    public function test_accepted_response_without_a_job_link_and_transport_timeout_do_not_allow_resubmission(): void
    {
        [$repository, $record] = $this->context();
        $response = new EHealthResponse(new \GuzzleHttp\Psr7\Response(202, [], json_encode(['data' => ['id' => $record->uuid, 'status' => 'active']], JSON_THROW_ON_ERROR)), \App\Classes\eHealth\Api\Job::validateSubmissionResponse(...));
        $this->assertNull(RemoteEHealthLinksProcessing::submitOperation($record, ['status' => 'recalled'], fn () => $response));
        $this->assertSame('active', $record->fresh()->status);
        $this->assertTrue($repository->hasPendingSubmission($record));
        $link = $record->ehealthLinks()->firstOrFail();
        $repository->finishSubmission($link, ['status' => 'failed', 'error' => ['message' => 'Synthetic confirmed rejection']]);
        try {
            RemoteEHealthLinksProcessing::submitOperation($record, ['status' => 'recalled'], fn () => throw new \App\Exceptions\EHealth\EHealthResponseException(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(408))));
            $this->fail('A timeout must propagate and retain the pending receipt.');
        } catch (\App\Exceptions\EHealth\EHealthResponseException) {
            $this->assertTrue($repository->hasPendingSubmission($record));
            $this->assertSame('active', $record->fresh()->status);
        }
        Notification::assertNothingSent();
    }

    public function test_processed_referral_imports_the_confirmed_activity_and_remaining_quantity(): void
    {
        [$repository, $record, $user, $person] = $this->context();
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $plan = app(\App\Repositories\CarePlanRepository::class)->storeResource($planResource, $person->id, legalEntity()->id);
        $activityResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $activityResource['detail']['kind'] = 'service_request';
        $activity = app(\App\Repositories\CarePlanActivityRepository::class)->storeResource($activityResource, $plan);
        $resource = $repository->getResource($record);
        $resource['basedOn'] = [$activityResource['carePlan'], ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'activity']]], 'value' => $activity->uuid]]];
        $record = $repository->storeResource($resource, $person->id);
        $link = $repository->beginSubmission($record, ['status' => 'active']);
        $activityResource['detail']['status'] = 'in-progress';
        $activityResource['detail']['remainingQuantity']['value'] = 0.5;
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('validate')->once()->andReturn($activityResource);
        $api = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getDetails')->once()->with($person->uuid, $plan->uuid, $activity->uuid)->andReturn($details);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $api);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'processed', 'status_code' => 200, 'result' => ['id' => $record->uuid, 'status' => 'active']]);
        $job = new ServiceRequestProcessingHarness($link, legalEntity(), standalone: true);
        $job->user = $user;
        $job->processConfirmed($response);
        $actual = app(\App\Repositories\CarePlanActivityRepository::class)->getResource($activity->id)['detail'];
        $this->assertSame('in-progress', $actual['status']);
        $this->assertSame(0.5, $actual['remainingQuantity']['value']);
        $this->assertFalse($repository->hasPendingSubmission($record));
    }

    public function test_execution_receipt_keeps_clinical_status_and_records_execution_progress(): void
    {
        [$repository, $record] = $this->context();
        $link = $repository->beginSubmission($record, ['status' => 'active', 'programProcessingStatus' => 'in_progress'], 'service_request:use', ['execution' => true]);
        $repository->finishSubmission($link, ['status' => 'processed']);
        $actual = $repository->getResource($record->fresh());
        $this->assertSame('active', $actual['status']);
        $this->assertSame('in_progress', $actual['programProcessingStatus']);
        $this->assertArrayNotHasKey('links', $actual);
    }
}

class ServiceRequestProcessingHarness extends RemoteEHealthLinksProcessing
{
    public function processConfirmed(EHealthResponse $response): void
    {
        $this->token = 'synthetic-token';
        $this->processResponse($response);
    }
}
