<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestDetails;
use App\Classes\eHealth\Api\ServiceRequest as ExecutorApi;
use App\Core\Arr;
use App\Dto\ServiceRequest\Approval as ReferralApprovalData;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\Concerns\ReadsClinicalSnapshots;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ServiceRequestFhirApiTest extends TestCase
{
    use ReadsClinicalSnapshots {
        fetchServiceRequestSnapshot as private fetchSignedSnapshot;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ehealth.api.domain', 'https://ehealth.invalid');
        Http::preventStrayRequests();
    }

    public function test_cancel_and_recall_preserve_the_fresh_raw_resource(): void
    {
        $resource = $this->detail();
        $resource['remote_extension'] = ['flag' => false, 'empty' => [], 'numeric_keys' => (object) ['0' => 'value']];
        $resource['inform_with'] = (object) [];
        $resource['based_on'][] = (object) [];
        Http::fake(['*' => Http::response(['data' => $resource])]);
        $api = $this->api();
        $this->instance(ServiceRequest::class, $api);
        $snapshot = $this->fetchSignedSnapshot('patient-test', $resource['id']);
        $actual = ServiceRequestForm::buildStatusContent($snapshot, ['coding' => [['system' => 'eHealth/service_request_recall_reasons', 'code' => 'synthetic-reason']]], 'Synthetic explanation');
        $this->assertSame(['coding' => [['system' => 'eHealth/service_request_recall_reasons', 'code' => 'synthetic-reason']]], $actual['status_reason']);
        $this->assertSame('Synthetic explanation', $actual['explanatory_letter']);
        unset($actual['status_reason'], $actual['explanatory_letter']);
        $this->assertSame(json_encode($resource, JSON_THROW_ON_ERROR), json_encode($actual, JSON_THROW_ON_ERROR));
        $this->assertFalse($actual['remote_extension']['flag']);
        $this->assertSame([], $actual['remote_extension']['empty']);
        $this->assertSame(0, $actual['remaining_quantity']['value']);
    }

    public function test_mismatched_snapshot_identity_blocks_signing(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->detail()])]);
        $this->expectException(ValidationException::class);
        $this->instance(ServiceRequest::class, $this->api());
        $this->fetchSignedSnapshot('patient-test', '10050000-0000-4000-8000-000000000999');
    }

    public function test_detail_maps_to_approval_data_and_preserves_both_legacy_key_formats(): void
    {
        $detail = $this->detail();
        unset($detail['occurrence_period']);
        $detail['occurrence_date_time'] = '2026-11-01T10:20:30Z';
        $detail['inserted_at'] = $detail['occurrence_date_time'];
        Http::fake(['*' => Http::response(['data' => $detail])]);

        $api = $this->api();
        $result = $api->getDetails('patient-test', $detail['id'])->validate();
        $this->assertInstanceOf(ServiceRequestDetails::class, $result);
        $this->assertSame($detail, $result->all());
        $approval = objectMapper()->map($result, ReferralApprovalData::class);
        $this->assertSame($detail['status'], $approval->status);
        $this->assertSame($detail['permitted_resources'], $approval->permittedResources);
        $this->assertSame($detail, $api->getById('patient-test', $detail['id'], preserveApiKeys: true)->validate());

        $normalized = $api->getById('patient-test', $detail['id'])->validate();
        $this->assertSame($detail['id'], $normalized['uuid']);
        $this->assertSame($detail['inserted_at'], $normalized['ehealth_inserted_at']);
        $this->assertArrayNotHasKey('id', $normalized);
    }

    public function test_detail_with_a_display_only_subject_and_no_permitted_resources_maps_to_approval(): void
    {
        $data = $this->detail();
        unset($data['permitted_resources'], $data['subject']['identifier']);
        Http::fake(['*' => Http::response(['data' => $data])]);

        $details = $this->api()->getDetails('patient-test', $data['id'])->validate();
        $approval = objectMapper()->map($details, ReferralApprovalData::class);
        $this->assertSame('active', $approval->status);
        $this->assertNull($approval->permittedResources);
    }

    #[DataProvider('invalidDetailFields')]
    public function test_invalid_detail_fields_are_rejected(array $overrides, array $missing = []): void
    {
        $data = $this->detail();
        $id = $data['id'];
        foreach ($overrides as $path => $value) {
            Arr::set($data, $path, $value);
        }
        Arr::forget($data, $missing);
        Http::fake(['*' => Http::response(['data' => $data])]);

        $this->expectException(ValidationException::class);
        $this->api()->getDetails('patient-test', $id)->validate();
    }

    public static function invalidDetailFields(): iterable
    {
        yield 'wrong UUID' => [['id' => '10050000-0000-4000-8000-000000000999']];
        yield 'wrong subject' => [['subject.identifier.value' => 'other-patient']];
        yield 'conflicting patient reference' => [['patient.identifier.value' => 'other-patient']];
        yield 'missing requester' => [[], ['requester_employee']];
        yield 'missing occurrence' => [[], ['occurrence_period']];
        yield 'two occurrences' => [['occurrence_date_time' => '2026-11-01T10:20:30Z']];
        yield 'string quantity' => [['remaining_quantity.value' => '0']];
        yield 'object as list' => [['reason_reference' => (object) []]];
        yield 'invalid link' => [['permitted_resources.0.identifier' => []]];
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_both_array_representations_reject_another_referral(bool $preserveApiKeys): void
    {
        Http::fake(['*' => Http::response(['data' => $this->detail()])]);

        $this->expectException(ValidationException::class);
        $this->api()->getById('patient-test', '10050000-0000-4000-8000-000000000999', preserveApiKeys: $preserveApiKeys)->validate();
    }

    public function test_a_detail_list_is_rejected_even_when_it_contains_the_requested_resource(): void
    {
        $data = $this->detail();
        Http::fake(['*' => Http::response(['data' => [$data]])]);

        $this->expectException(ValidationException::class);
        $this->api()->getDetails('patient-test', $data['id'])->validate();
    }

    public function test_an_accepted_response_is_rejected_even_when_it_contains_the_requested_resource(): void
    {
        $data = $this->detail();
        Http::fake(['*' => Http::response(['data' => $data], 202)]);

        $this->expectException(ValidationException::class);
        $this->api()->getDetails('patient-test', $data['id'])->validate();
    }

    public function test_a_business_error_blocks_use_of_detail_even_with_http_200(): void
    {
        $data = $this->detail();
        Http::fake(['*' => Http::response(['data' => $data, 'error' => ['message' => 'Synthetic failure']])]);

        $this->expectException(ValidationException::class);
        $this->api()->getDetails('patient-test', $data['id'])->validate();
    }

    public function test_remote_failure_blocks_snapshot_signing(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Synthetic remote failure']], 503)]);
        $this->expectException(EHealthResponseException::class);
        $this->instance(ServiceRequest::class, $this->api());
        $this->fetchSignedSnapshot('patient-test', $this->resource()['id']);
    }

    public function test_snapshot_of_another_patient_is_rejected(): void
    {
        $resource = $this->detail();
        $resource['remote_extension'] = ['flag' => false, 'empty' => []];
        $resource['subject']['identifier']['value'] = 'other-patient';
        Http::fake(['*' => Http::response(['data' => $resource])]);
        $this->expectException(ValidationException::class);
        $this->instance(ServiceRequest::class, $this->api());
        $this->fetchSignedSnapshot('patient-test', $resource['id']);
    }

    public function test_empty_explanation_is_rejected_before_signing(): void
    {
        $this->expectException(ValidationException::class);
        ServiceRequestForm::buildStatusContent(Arr::toSnakeCase($this->resource()), ['coding' => [['system' => 'synthetic/reasons', 'code' => 'synthetic-reason']]], '  ');
    }

    public function test_create_content_excludes_server_metadata_and_preserves_quantity(): void
    {
        $content = ServiceRequestForm::buildCreateContent($this->resource());
        $this->assertSame(1.23456789, $content['quantity']['value']);
        $this->assertArrayNotHasKey('remaining_quantity', $content);
        $this->assertArrayNotHasKey('remote_extension', $content);
        $this->assertArrayNotHasKey('service_request', $content);
        $this->assertCount(2, $content['based_on']);
    }

    public function test_complete_uses_a_single_result_reference_and_keeps_only_allowed_options(): void
    {
        $uuid = '10050000-0000-4000-8000-000000000002';
        foreach (['encounter', 'procedure', 'diagnostic_report'] as $type) {
            $actual = ServiceRequestForm::buildCompleteContent($uuid, $type, ['status_reason' => ['coding' => [['system' => 'synthetic/reason', 'code' => 'TEST']]], 'injected' => 'discard']);
            $this->assertSame($type, data_get($actual, 'completed_with.identifier.type.coding.0.code'));
            $this->assertSame($uuid, data_get($actual, 'completed_with.identifier.value'));
            $this->assertArrayNotHasKey('based_on', $actual);
            $this->assertArrayNotHasKey('injected', $actual);
            $this->assertArrayHasKey('status_reason', $actual);
        }
    }

    public function test_signed_status_envelope_does_not_duplicate_fields_outside_the_signature(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'recalled']])]);
        $this->api()->recall('patient-test', $this->resource()['id'], ServiceRequestForm::buildSignedEnvelope(['signed_data' => 'immutable-signed-content', 'status_reason' => 'outside', 'explanatory_letter' => 'outside']));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->data() === ['signed_data' => 'immutable-signed-content', 'signed_data_encoding' => 'base64']);
    }

    public function test_endpoint_returns_pending_or_failed_data_without_polling(): void
    {
        Http::fakeSequence()->push(['data' => ['status' => 'pending']])->push(['data' => ['status' => 'processing']])->push(['data' => ['status' => 'failed']]);
        foreach (['pending', 'processing', 'failed'] as $index => $status) {
            $response = $this->api()->recall('patient-test', $this->resource()['id'], ['signed_data' => 'synthetic-signed-content', 'signed_data_encoding' => 'base64']);
            $this->assertSame($status, $response->getData()['status']);
            Http::assertSentCount($index + 1);
        }
    }

    public function test_cancel_usage_calls_documented_release_with_no_clinical_cancel_payload(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'pending', 'job_id' => 'release-job']], 202)]);
        $factory = Http::getFacadeRoot();
        $api = new ExecutorApi($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();
        $response = $api->cancelUsage('request-test', 'patient-test', ['signed_data' => 'discard', 'explanatory_letter' => 'local explanation']);
        $this->assertSame('pending', $response->getData()['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/service_requests/request-test/actions/release') && $request->data() === []);
    }

    private function detail(): array
    {
        return Arr::toSnakeCase($this->resource()) + [
            'requisition' => '0000-1111-2222-3333',
            'status_history' => [],
            'subject' => ['display_value' => '', 'identifier' => ['value' => 'patient-test', 'type' => ['text' => 'person']]],
            'used_by_employee' => ['display_value' => ''],
            'used_by_legal_entity' => ['display_value' => ''],
            'expiration_date' => '2026-12-01T10:20:30Z',
        ];
    }

    private function resource(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function api(): ServiceRequest
    {
        $factory = Http::getFacadeRoot();
        $api = new ServiceRequest($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();

        return $api;
    }
}
