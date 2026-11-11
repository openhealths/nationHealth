<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\Api\ServiceRequest as ExecutorApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\ServiceRequestSync;
use App\Livewire\Person\Records\PatientReferrals;
use App\Livewire\Referral\ReferralIndex;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Policies\DiagnosticReportPolicy;
use App\Policies\EncounterPolicy;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Auth\Access\Response;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\ClinicalEncounterView;
use Tests\TestCase;

class ClinicalReferralCompletionTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        Bus::fake();
        Notification::fake();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        foreach ([ServiceDictionary::KEY, MedicalProgramDictionary::KEY] as $key) {
            Cache::forever($key, []);
            Cache::forever($key.':fresh', true);
        }
        $this->partialMock(EncounterPolicy::class)->shouldReceive('view')->andReturn(Response::allow());
        $this->partialMock(DiagnosticReportPolicy::class)->shouldReceive('view')->andReturn(Response::allow());
    }

    private function context(array $scopes = ['service_request:read', 'service_request:complete', 'encounter:read']): array
    {
        $context = $this->compositionFixture(scopes: $scopes);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['id'] = (string) Str::uuid();
        $resource['subject']['identifier']['value'] = $context['person']->uuid;
        $resource['requesterEmployee']['identifier']['value'] = $context['employee']->uuid;
        $resource['requesterLegalEntity']['identifier']['value'] = $context['legalEntity']->uuid;
        $resource['requisition'] = '1234-5678-9012-3456';
        $resource['authoredOn'] = '2026-11-06T07:00:00Z';
        $resource['programProcessingStatus'] = 'in_progress';
        $resource['usedByLegalEntity'] = ['identifier' => ['value' => $context['legalEntity']->uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']]]], 'displayValue' => 'Наш заклад'];
        $resource['usedByEmployee'] = ['displayValue' => ''];
        $resource['statusHistory'] = [];
        $resource['expirationDate'] = '2027-11-11T00:00:00Z';
        Cache::forever(ServiceDictionary::KEY, [['id' => $resource['code']['identifier']['value'], 'code' => 'TEST', 'name' => 'Group', 'is_active' => true, 'request_allowed' => true, 'services' => [['id' => '10050000-0000-4000-8000-000000000022', 'code' => 'TEST-LEAF', 'name' => 'Service', 'category' => 'diagnostic_procedure', 'is_active' => true, 'request_allowed' => true]]]]);
        $this->partialMock(ServiceRequestApi::class)->shouldReceive('getById')->andReturn($this->response($resource))->byDefault();
        unset($resource['basedOn']);
        $record = Repository::serviceRequest()->importResource($resource, $context['person']->id);
        $encounter = $this->encounter($context['person'], $record->uuid);

        return $context + compact('record', 'resource', 'encounter');
    }

    private function encounter(Person $person, string $referralUuid, string $status = 'finished'): Encounter
    {
        $type = CodeableConcept::create();
        $type->coding()->create(['system' => 'eHealth/encounter_types', 'code' => 'AMB']);
        $episode = Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'name' => 'Synthetic episode', 'managing_organization_id' => Identifier::create(['value' => legalEntity()->uuid])->id]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => $status,
            'incoming_referral_id' => Identifier::create(['value' => $referralUuid])->id,
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'class_id' => Coding::firstOrCreate(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => $type->id,
        ]);
        $encounter->actionReferences()->attach(Identifier::create(['value' => '10050000-0000-4000-8000-000000000022'])->id);
        $encounter->period()->create(['start' => '2026-11-06T08:00:00Z', 'end' => '2026-11-06T09:00:00Z']);

        return $encounter;
    }

    private function response(array $data, array $paging = []): EHealthResponse
    {
        return new EHealthResponse(new HttpResponse(200, [], json_encode(['data' => $data, 'paging' => $paging])), static fn ($response): array => $response->getData());
    }

    public function test_incoming_card_uses_persisted_details_and_opens_the_completion_form(): void
    {
        $context = $this->context();
        $component = Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ]);
        $details = $component->instance()->incomingReferral();
        $this->assertSame($context['record']->uuid, $details['uuid']);
        $this->assertSame('1234-5678-9012-3456', $details['requestNumber']);
        $this->assertSame('1.23', $details['quantity']);
        $this->assertSame($context['employee']->party->fullName, $details['doctorName']);
        $this->assertStringContainsString('06.11.2026', $details['createdLabel']);
        $this->assertNotSame('—', $details['periodLabel']);
        $component->call('openReferralRedeemModal')->assertSet('showReferralRedeemModal', true)
            ->assertSet('referralCompletionUuid', $context['encounter']->uuid);
    }

    public function test_registry_shows_requisition_and_uuid_separately_with_program_details(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        $resource['program']['displayValue'] = 'Програма медичних гарантій для тесту';
        Repository::serviceRequest()->importResource($resource, $context['person']->id);
        Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->assertSee('1234-5678-9012-3456')->assertSee($context['record']->uuid)
            ->assertSee('Програма медичних гарантій для тесту')
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->call('toggleDetails', $context['record']->uuid)
            ->assertSee(__('referrals.registry.number_hint'))->assertSee('(UUID)')
            ->assertSee(__('referrals.registry.copy_identifier'));
    }

    public function test_program_uuid_resolves_to_the_dictionary_name_without_a_snapshot_label(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        unset($resource['program']['displayValue']);
        Cache::forever(MedicalProgramDictionary::KEY, [[
            'id' => $resource['program']['identifier']['value'], 'name' => 'Назва програми з довідника',
        ]]);
        Repository::serviceRequest()->importResource($resource, $context['person']->id);
        Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->assertSee('Назва програми з довідника');
    }

    public function test_missing_number_is_not_replaced_with_uuid_and_missing_program_is_explicit(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        unset($resource['requisition'], $resource['program']);
        Repository::serviceRequest()->importResource($resource, $context['person']->id);
        $component = Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->assertSee(__('referrals.registry.number_pending'))->assertSee(__('referrals.registry.program_not_specified'));
        $this->assertSame('', $component->get('referrals')[0]['requestNumber']);
        $this->assertSame($context['record']->uuid, $component->get('referrals')[0]['uuid']);
    }

    public function test_a_newly_processed_encounter_prompts_completion_and_can_reopen_it(): void
    {
        $context = $this->context();
        $context['encounter']->update(['status' => 'draft']);
        $this->mock(ExecutorApi::class)->shouldReceive('complete')->once()->andReturn($this->response(['status' => 'pending', 'job_id' => 'fresh-encounter-completion']));
        $this->mock(Job::class)->shouldReceive('getDetailsByHref')->once()->andReturn($this->response(['status' => 'processed', 'result' => ['status' => 'completed']]));
        $component = Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->assertSet('showReferralRedeemModal', false);
        $context['encounter']->update(['status' => 'finished']);
        $component->call('showAdditionalActions')->assertSet('isFinished', true)
            ->assertSet('showReferralRedeemModal', true)->assertSet('referralCompletionUuid', $context['encounter']->uuid)
            ->set('showReferralRedeemModal', false)->call('openReferralRedeemModal')
            ->assertSet('showReferralRedeemModal', true)
            ->call('redeemReferral')->assertSee(__('care-plan.submission_pending'))
            ->call('checkReferralCompletion')->assertSee(__('referrals.messages.complete_success'));
        $this->assertSame('completed', $context['record']->fresh()->status);
    }

    public function test_imaging_does_not_offer_an_encounter_or_submit_an_invalid_completion(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        $resource['category']['coding'][0]['code'] = 'imaging';
        Repository::serviceRequest()->importResource($resource, $context['person']->id);
        $this->mock(ExecutorApi::class)->shouldNotReceive('complete');
        $component = Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->call('openReferralRedeemModal')->assertSet('referralCompletionTypes', ['diagnostic_report'])
            ->assertSet('referralCompletionType', 'diagnostic_report')->assertSet('referralCompletionUuid', '')
            ->assertSee(__('referrals.messages.completion_record_empty', ['type' => __('care-plan.emz_type.diagnostic_report')]))
            ->set('referralCompletionType', 'encounter')->set('referralCompletionUuid', $context['encounter']->uuid)
            ->call('redeemReferral')->assertSee(__('care-plan.referral_complete_emz_not_linked'));
        $this->assertSame(0, $context['record']->ehealthLinks()->count());
    }

    public function test_completion_choices_have_clinical_names_and_dates_and_exclude_invalid_records(): void
    {
        $context = $this->context();
        $this->encounter($context['person'], $context['record']->uuid, 'draft');
        $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $this->encounter($other, $context['record']->uuid);
        $choices = Repository::serviceRequest()->completionResources($context['person'], $context['record']->uuid, 'encounter');
        $this->assertCount(1, $choices);
        $this->assertStringContainsString('Амбулаторний', $choices[0]['label']);
        $this->assertStringContainsString('06.11.2026', $choices[0]['label']);
        $this->assertStringNotContainsString($context['encounter']->uuid, $choices[0]['label']);
    }

    public function testStatusButtonOpensTheJobModalAndRefreshesPendingCompletionWithoutResubmitting(): void
    {
        $context = $this->context();
        $link = Repository::serviceRequest()->beginSubmission($context['record'], ['status' => 'completed'], 'service_request:complete');
        $link->update(['href' => '/api/jobs/synthetic-referral-completion']);
        $this->mock(ExecutorApi::class)->shouldNotReceive('complete');
        $this->mock(Job::class)->shouldReceive('getDetailsByHref')->twice()
            ->with('/api/jobs/synthetic-referral-completion')
            ->andReturn($this->response(['status' => 'pending']), $this->response(['status' => 'processed', 'result' => ['status' => 'completed']]));

        $component = Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->call('openReferralStatusModal')->assertSet('showReferralStatusModal', true)
            ->assertSet('referralSubmissionPending', true)
            ->assertSet('referralCompletionJob.id', 'synthetic-referral-completion')
            ->assertSet('referralCompletionJob.status', JobStatus::PENDING->label())
            ->assertSee(__('referrals.messages.completion_job_details'))
            ->assertSee('1234-5678-9012-3456')->assertSee($context['record']->uuid);
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);

        $component->call('openReferralStatusModal')->assertSet('showReferralStatusModal', true)
            ->assertSet('referralSubmissionPending', false)
            ->assertSet('referralCompletionJob.status', JobStatus::PROCESSED->label())
            ->assertSet('referralCompletionJob.error', null);
        $this->assertSame('completed', $context['record']->fresh()->status);
    }

    public function testStatusModalShowsTranslatedJobRejection(): void
    {
        $context = $this->context();
        $link = Repository::serviceRequest()->beginSubmission($context['record'], ['status' => 'completed'], 'service_request:complete');
        $link->update(['href' => '/api/jobs/synthetic-failed-completion']);
        $job = Mockery::mock(Job::class)->makePartial();
        $job->shouldReceive('getDetailsByHref')->once()->andReturn($this->response(['status' => 'failed', 'error' => [
            'type' => 'validation_failed', 'invalid' => [['entry' => '$.completed_with.identifier.type', 'rules' => [['description' => 'Not allowed for this category']]]],
        ]]));
        $this->instance(Job::class, $job);

        Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->call('openReferralStatusModal')->assertSet('showReferralStatusModal', true)
            ->assertSet('referralCompletionJob.status', JobStatus::FAILED->label())
            ->assertSee(__('referrals.messages.completion_type_not_allowed'));
        $this->assertSame(JobStatus::FAILED->value, $link->fresh()->status);
        $this->assertNotSame('completed', $context['record']->fresh()->status);
    }

    public function testStatusDetailsAreDeniedBeforeFetchingOrUpdatingTheJob(): void
    {
        $context = $this->context(['service_request:read']);
        $link = Repository::serviceRequest()->beginSubmission($context['record'], ['status' => 'completed'], 'service_request:complete');
        $link->update(['href' => '/api/jobs/synthetic-protected-job']);
        $this->mock(Job::class)->shouldNotReceive('getDetailsByHref');

        Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->call('openReferralStatusModal')->assertSet('showReferralStatusModal', false)
            ->assertSee(__('referrals.messages.complete_forbidden'));
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
    }

    public function testStatusDetailsNeverFetchAnotherFacilitysCompletionJob(): void
    {
        $context = $this->context();
        $link = Repository::serviceRequest()->beginSubmission($context['record'], ['status' => 'completed'], 'service_request:complete');
        $link->update(['href' => '/api/jobs/another-facility-job']);
        $data = $link->job->request_data;
        $data['legalEntityId'] = $context['legalEntity']->id + 100;
        $link->job()->update(['request_data' => $data]);
        $this->mock(Job::class)->shouldNotReceive('getDetailsByHref');

        Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->call('openReferralStatusModal')->assertSet('showReferralStatusModal', false)
            ->assertSee(__('referrals.messages.completion_job_missing'));
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
    }

    public function test_patient_list_completes_once_and_displays_the_confirmed_result(): void
    {
        $context = $this->context();
        $this->mock(ExecutorApi::class)->shouldReceive('complete')->once()->with($context['record']->uuid, Mockery::on(
            fn (array $payload): bool => data_get($payload, 'completed_with.identifier.value') === $context['encounter']->uuid
        ))->andReturn($this->response(['status' => 'pending', 'job_id' => 'synthetic-completion']));
        $this->mock(Job::class)->shouldReceive('getDetailsByHref')->once()->andReturn($this->response(['status' => 'processed', 'result' => ['status' => 'completed']]));
        $component = Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('openReferralRedeemModal', $context['record']->uuid)->assertSet('showReferralRedeemModal', true)
            ->call('redeemReferral')->assertSee(__('care-plan.submission_pending'));
        $this->assertSame(1, $context['record']->ehealthLinks()->count());
        try {
            app(ReferralRequestLifecycleService::class)->completeReferral($context['record']->uuid, $context['encounter']->uuid);
            $this->fail('Duplicate pending completion was sent');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }
        $component->call('checkPendingCompletions')->assertSee(__('referrals.messages.complete_success'))->assertSet('pendingCompletions', []);
        $this->assertSame('completed', $context['record']->fresh()->status);
        $component->call('checkPendingCompletions')->assertDontSee(__('referrals.messages.complete_success'));
    }

    public function test_async_category_rejection_is_visible_and_leaves_the_referral_uncompleted(): void
    {
        $context = $this->context();
        $link = Repository::serviceRequest()->beginSubmission($context['record'], ['status' => 'completed'], 'service_request:complete');
        $link->update(['href' => '/api/jobs/rejected-completion']);
        $job = Mockery::mock(Job::class)->makePartial();
        $job->shouldReceive('getDetailsByHref')->once()->andReturn($this->response(['status' => 'failed', 'error' => [
            'type' => 'validation_failed', 'invalid' => [['entry' => '$.completed_with.identifier.type', 'rules' => [['description' => 'Not allowed for this category']]]],
        ]]));
        $this->instance(Job::class, $job);
        Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('checkPendingCompletions')->assertSee(__('referrals.messages.completion_type_not_allowed'))
            ->assertSet('pendingCompletions', []);
        $this->assertSame(JobStatus::FAILED->value, $link->fresh()->status);
        $this->assertNotSame('completed', $context['record']->fresh()->status);
    }

    public function test_completion_denial_has_visible_feedback_without_api_or_database_side_effects(): void
    {
        $context = $this->context(['service_request:read']);
        $this->mock(ExecutorApi::class)->shouldNotReceive('complete');
        Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('openReferralRedeemModal', $context['record']->uuid)
            ->assertSet('showReferralRedeemModal', false)->assertSee(__('referrals.messages.complete_forbidden'));
        Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ])->call('openReferralRedeemModal')->assertSet('showReferralRedeemModal', false)
            ->assertSee(__('referrals.messages.complete_forbidden'));
        $this->assertSame(0, $context['record']->ehealthLinks()->count());
    }

    public function test_synchronization_imports_all_pages_and_refreshes_the_patient_list(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        $resource['id'] = (string) Str::uuid();
        $resource['requisition'] = 'NEW-REFERRAL';
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('withToken')->with('synthetic-token')->andReturnSelf();
        $query = ['page_size' => 100, 'requester_legal_entity' => $context['legalEntity']->uuid];
        $api->shouldReceive('getBySearchParams')->once()->with($context['person']->uuid, $query + ['status' => 'active', 'page' => 1])
            ->andReturn($this->response([], ['page_number' => 1, 'total_pages' => 2]));
        $api->shouldReceive('getBySearchParams')->once()->with($context['person']->uuid, $query + ['status' => 'active', 'page' => 2])
            ->andReturn($this->response([['id' => $resource['id']]], ['page_number' => 2, 'total_pages' => 2]));
        foreach (['completed', 'entered_in_error', 'recalled'] as $status) {
            $api->shouldReceive('getBySearchParams')->once()->with($context['person']->uuid, $query + ['status' => $status, 'page' => 1])->andReturn($this->response([]));
        }
        $api->shouldReceive('getById')->once()->with($context['person']->uuid, $resource['id'], [], true)->andReturn($this->response($resource));
        $this->instance(ServiceRequestApi::class, $api);
        $component = Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('sync')->assertSee(__('referrals.messages.sync_started_own'));
        Bus::assertBatched(function (PendingBatch $batch): bool {
            $jobs = $batch->jobs->all();
            while ($jobs !== []) {
                $next = array_shift($jobs);
                [$job, $fake] = $next->withFakeBatch(options: $batch->options);
                $job->handle();
                array_push($jobs, ...$fake->added);
            }

            return true;
        });
        $component->call('loadReferrals')->assertSee('NEW-REFERRAL');
        $this->assertDatabaseHas('service_request_requests', ['uuid' => $resource['id'], 'person_id' => $context['person']->id]);
    }

    public function testPaginationJobsOverrideStalePageAndStatusWhilePreservingAccessFilters(): void
    {
        $context = $this->context();
        $resource = array_replace($context['resource'], [
            'id' => (string) Str::uuid(), 'status' => 'completed', 'programProcessingStatus' => 'completed',
        ]);
        $query = [
            'page_size' => 100, 'requester_legal_entity' => $context['legalEntity']->uuid,
            'page' => 1, 'status' => 'active',
        ];
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('withToken')->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getBySearchParams')->once()->with($context['person']->uuid, array_replace($query, [
            'page' => 2, 'status' => 'completed',
        ]))->andReturn($this->response([['id' => $resource['id']]], ['page_number' => 2, 'total_pages' => 3]));
        $api->shouldReceive('getBySearchParams')->once()->with($context['person']->uuid, array_replace($query, [
            'page' => 3, 'status' => 'completed',
        ]))->andReturn($this->response([], ['page_number' => 3, 'total_pages' => 3]));
        $api->shouldReceive('getById')->once()->with($context['person']->uuid, $resource['id'], [], true)
            ->andReturn($this->response($resource));
        $this->instance(ServiceRequestApi::class, $api);
        $options = ['person_id' => $context['person']->id, 'token' => encrypt('synthetic-token', false), 'query' => $query];
        $jobs = [new ServiceRequestSync(page: 2, status: 'completed')];
        $handled = 0;

        while ($jobs !== [] && $handled < 4) {
            $next = array_shift($jobs);
            [$job, $batch] = $next->withFakeBatch(options: $options);
            $job->handle();
            array_push($jobs, ...$batch->added);
            $handled++;
        }

        $this->assertSame([], $jobs);
        $this->assertSame(3, $handled);
        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $resource['id'], 'person_id' => $context['person']->id, 'status' => 'completed',
        ]);
    }

    public function test_sync_access_denial_shows_the_reason_and_does_not_dispatch_jobs(): void
    {
        $context = $this->context();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('getBySearchParams')->once()->andThrow(new EHealthResponseException(
            new ClientResponse(new HttpResponse(403, [], '{"error":{"message":"Access denied"}}'))
        ));
        $this->instance(ServiceRequestApi::class, $api);
        Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('sync')->assertSee(__('referrals.messages.sync_access_denied'))
            ->assertDontSee(__('referrals.messages.sync_failed'))->assertSet('syncBatchId', null);
        Bus::assertNothingBatched();
    }

    public function test_individual_forbidden_detail_is_counted_without_discarding_the_batch(): void
    {
        $context = $this->context();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('getById')->once()->andThrow(new EHealthResponseException(
            new ClientResponse(new HttpResponse(403, [], '{"error":{"message":"Access denied"}}'))
        ));
        $this->instance(ServiceRequestApi::class, $api);
        [$job, $batch] = (new ServiceRequestSync(resourceUuid: $context['record']->uuid))->withFakeBatch(options: [
            'person_id' => $context['person']->id, 'token' => encrypt('synthetic-token', false),
        ]);
        $job->handle();
        $this->assertSame(1, Cache::get('referral-sync-denied:'.$batch->id));
        $this->assertSame($context['record']->status, $context['record']->fresh()->status);
    }

    public function test_imaging_referral_uses_a_linked_diagnostic_report_and_the_global_list_tracks_completion(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        $resource['category']['coding'][0]['code'] = 'imaging';
        $record = Repository::serviceRequest()->importResource($resource, $context['person']->id);
        $report = DiagnosticReport::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $context['person']->id, 'status' => 'final',
            'based_on_id' => Identifier::create(['value' => $record->uuid])->id,
            'code_id' => Identifier::create(['value' => '10050000-0000-4000-8000-000000000022'])->id,
            'recorded_by_id' => Identifier::create(['value' => $context['employee']->uuid])->id,
            'managing_organization_id' => Identifier::create(['value' => $context['legalEntity']->uuid])->id,
            'issued' => now(), 'primary_source' => true,
        ]);
        $row = ['id' => $record->uuid, 'status' => 'active', 'program_processing_status' => 'in_progress',
            'used_by_legal_entity' => ['identifier' => ['value' => $context['legalEntity']->uuid]],
            'subject' => ['identifier' => ['value' => $context['person']->uuid]], 'category' => ['coding' => [['code' => 'imaging']]]];
        $api = $this->partialMock(ServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->times(4)->andReturn($this->response([$row]));
        $api->shouldReceive('getById')->once()->andReturn($this->response($resource));
        $this->mock(ExecutorApi::class)->shouldReceive('complete')->once()->with($record->uuid, Mockery::on(
            fn (array $payload): bool => data_get($payload, 'completed_with.identifier.type.coding.0.code') === 'diagnostic_report'
                && data_get($payload, 'completed_with.identifier.value') === $report->uuid
        ))->andReturn($this->response(['status' => 'pending', 'job_id' => 'diagnostic-completion']));
        $this->mock(Job::class)->shouldReceive('getDetailsByHref')->once()->andReturn($this->response(['status' => 'processed', 'result' => ['status' => 'completed']]));
        Livewire::test(ReferralIndex::class, ['legalEntity' => $context['legalEntity']])
            ->set('requisition', '1234-5678-9012-3456')->call('search')
            ->call('openCompleteModal', $record->uuid)->assertSet('selectedEmzType', 'diagnostic_report')
            ->assertSee(__('care-plan.emz_type.diagnostic_report'))->set('selectedEmzUuid', $report->uuid)
            ->call('confirmComplete')->assertSee(__('care-plan.submission_pending'))
            ->call('checkPendingCompletions')->assertSet('searchResults.0.status', 'completed')
            ->assertSee(__('referrals.messages.complete_success'))->assertSet('pendingCompletions', []);
    }

    public function test_rate_limiting_releases_the_sync_job_without_losing_the_page(): void
    {
        $context = $this->context();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('getBySearchParams')->once()->andThrow(new EHealthResponseException(
            new ClientResponse(new HttpResponse(429, ['Retry-After' => '75'], '{"error":{"message":"Too many requests"}}'))
        ));
        $this->instance(ServiceRequestApi::class, $api);
        [$job] = (new ServiceRequestSync(page: 2))->withFakeBatch(options: [
            'person_id' => $context['person']->id, 'token' => encrypt('synthetic-token', false), 'query' => [],
        ]);
        $job->withFakeQueueInteractions()->handle();
        $job->assertReleased(75);
        $job->assertNotFailed();
    }

    public function test_global_search_fetches_all_statuses_and_pages_and_removes_duplicates(): void
    {
        $context = $this->context();
        $first = ['id' => $context['record']->uuid, 'status' => 'active'];
        $second = ['id' => (string) Str::uuid(), 'status' => 'completed'];
        $api = Mockery::mock(ServiceRequestApi::class);
        foreach (['active', 'completed', 'entered_in_error', 'recalled'] as $status) {
            $api->shouldReceive('searchForServiceRequestsByParams')->once()->with([
                'requisition' => '1234-5678-9012-3456', 'status' => $status, 'page' => 1, 'page_size' => 100,
            ])->andReturn($this->response([$first], ['total_pages' => $status === 'completed' ? 2 : 1]));
        }
        $api->shouldReceive('searchForServiceRequestsByParams')->once()->with([
            'requisition' => '1234-5678-9012-3456', 'status' => 'completed', 'page' => 2, 'page_size' => 100,
        ])->andReturn($this->response([$first, $second], ['total_pages' => 2]));
        $this->instance(ServiceRequestApi::class, $api);
        Livewire::test(ReferralIndex::class, ['legalEntity' => $context['legalEntity']])
            ->set('requisition', '1234567890123456')->call('search')
            ->assertSet('hasSearched', true)->assertSet('searchResults', [$first, $second]);
    }

    public function testGlobalSearchFindsAnActiveReferralInProgressAndOffersCompletion(): void
    {
        $context = $this->context();
        $referral = [
            'id' => $context['record']->uuid, 'requisition' => '1234-5678-9012-3456',
            'status' => 'active', 'program_processing_status' => 'in_progress',
            'used_by_legal_entity' => ['identifier' => ['value' => $context['legalEntity']->uuid]],
            'subject' => ['identifier' => ['value' => $context['person']->uuid]],
        ];
        $api = Mockery::mock(ServiceRequestApi::class);
        foreach (['active', 'completed', 'entered_in_error', 'recalled'] as $status) {
            $api->shouldReceive('searchForServiceRequestsByParams')->once()->with([
                'requisition' => $referral['requisition'], 'status' => $status, 'page' => 1, 'page_size' => 100,
            ])->andReturn($this->response($status === 'active' ? [$referral] : []));
        }
        $api->shouldNotReceive('complete', 'process');
        $this->instance(ServiceRequestApi::class, $api);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $context['legalEntity']])
            ->set('requisition', $referral['requisition'])->call('search')
            ->assertSet('searchResults', [$referral])
            ->call('openCompleteModal', $referral['id'])
            ->assertSet('showCompleteModal', true)
            ->assertSet('referralToComplete', $referral['id']);
    }

    public function test_synchronization_denial_makes_no_ehealth_calls(): void
    {
        $context = $this->context(['encounter:read']);
        $this->mock(ServiceRequestApi::class)->shouldNotReceive('getBySearchParams', 'getById');
        Livewire::test(PatientReferrals::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('sync')->assertSee(__('service-requests.policy.search'));
        $this->assertDatabaseCount('service_request_requests', 1);
    }

    public function test_program_completion_requires_an_actual_member_service_and_known_episode_owner(): void
    {
        $context = $this->context();
        $encounter = $context['encounter'];
        $encounter->actionReferences()->sync([Identifier::create(['value' => (string) Str::uuid()])->id]);
        $this->assertSame([], Repository::serviceRequest()->completionResources($context['person'], $context['record']->uuid, 'encounter'));
        $encounter->actionReferences()->sync([Identifier::create(['value' => '10050000-0000-4000-8000-000000000022'])->id]);
        $this->assertSame([$encounter->uuid], array_column(Repository::serviceRequest()->completionResources($context['person'], $context['record']->uuid, 'encounter'), 'uuid'));
        Episode::whereUuid($encounter->episode->value)->update(['managing_organization_id' => null]);
        $this->assertSame([], Repository::serviceRequest()->completionResources($context['person'], $context['record']->uuid, 'encounter'));
    }

    public function test_fresh_remaining_quantity_blocks_completion_before_mutation_and_can_be_acknowledged(): void
    {
        $context = $this->context();
        $resource = $context['resource'];
        $resource['remainingQuantity'] = ['value' => 2, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'];
        app(ServiceRequestApi::class)->shouldReceive('getById')->twice()->andReturn($this->response($resource));
        $executor = $this->mock(ExecutorApi::class);
        $executor->shouldReceive('complete')->once()->andReturn($this->response(['status' => 'completed']));
        $service = app(ReferralRequestLifecycleService::class);
        try {
            $service->completeReferral($context['record']->uuid, $context['encounter']->uuid);
            $this->fail('A positive remaining quantity requires explicit acknowledgement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('acknowledgeRemainingQuantity', $exception->errors());
            $this->assertSame('active', $context['record']->fresh()->status);
            $this->assertFalse(Repository::serviceRequest()->hasPendingSubmission($context['record']));
        }
        $result = $service->completeReferral($context['record']->uuid, $context['encounter']->uuid, payload: ['acknowledge_remaining_quantity' => true]);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($context['encounter']->uuid, $context['record']->fresh()->completedWith->value);
    }
}
