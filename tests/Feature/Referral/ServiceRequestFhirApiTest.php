<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Core\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceRequestFhirApiTest extends TestCase
{
    use \App\Livewire\Concerns\ReadsClinicalSnapshots {
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
        $resource = Arr::toSnakeCase($this->resource());
        $resource['remote_extension'] = ['flag' => false, 'empty' => []];
        Http::fake(['*' => Http::response(['data' => $resource])]);
        $api = $this->api();
        $this->instance(ServiceRequest::class, $api);
        $snapshot = $this->fetchSignedSnapshot('patient-test', $resource['id']);
        $actual = \App\Livewire\Referral\Forms\ServiceRequestForm::buildStatusContent($snapshot, ['coding' => [['system' => 'eHealth/service_request_recall_reasons', 'code' => 'synthetic-reason']]], 'Synthetic explanation');
        $this->assertSame(['coding' => [['system' => 'eHealth/service_request_recall_reasons', 'code' => 'synthetic-reason']]], $actual['status_reason']);
        $this->assertSame('Synthetic explanation', $actual['explanatory_letter']);
        unset($actual['status_reason'], $actual['explanatory_letter']);
        $this->assertSame($resource, $actual);
        $this->assertFalse($actual['remote_extension']['flag']);
        $this->assertSame([], $actual['remote_extension']['empty']);
        $this->assertSame(0, $actual['remaining_quantity']['value']);
    }

    public function test_mismatched_snapshot_identity_blocks_signing(): void
    {
        Http::fake(['*' => Http::response(['data' => Arr::toSnakeCase($this->resource())])]);
        $this->expectException(ValidationException::class);
        $this->instance(ServiceRequest::class, $this->api());
        $this->fetchSignedSnapshot('patient-test', '10050000-0000-4000-8000-000000000999');
    }

    public function test_remote_failure_blocks_snapshot_signing(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Synthetic remote failure']], 503)]);
        $this->expectException(\App\Exceptions\EHealth\EHealthResponseException::class);
        $this->instance(ServiceRequest::class, $this->api());
        $this->fetchSignedSnapshot('patient-test', $this->resource()['id']);
    }

    public function test_snapshot_of_another_patient_is_rejected(): void
    {
        $resource = Arr::toSnakeCase($this->resource());
        $resource['remote_extension'] = ['flag' => false, 'empty' => []];
        $resource['subject'] = ['identifier' => ['value' => 'other-patient']];
        Http::fake(['*' => Http::response(['data' => $resource])]);
        $this->expectException(ValidationException::class);
        $this->instance(ServiceRequest::class, $this->api());
        $this->fetchSignedSnapshot('patient-test', $resource['id']);
    }

    public function test_empty_explanation_is_rejected_before_signing(): void
    {
        $this->expectException(ValidationException::class);
        \App\Livewire\Referral\Forms\ServiceRequestForm::buildStatusContent(Arr::toSnakeCase($this->resource()), ['coding' => [['system' => 'synthetic/reasons', 'code' => 'synthetic-reason']]], '  ');
    }

    public function test_create_content_excludes_server_metadata_and_preserves_quantity(): void
    {
        $content = \App\Livewire\Referral\Forms\ServiceRequestForm::buildCreateContent($this->resource());
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
            $actual = \App\Livewire\Referral\Forms\ServiceRequestForm::buildCompleteContent($uuid, $type, ['status_reason' => ['coding' => [['system' => 'synthetic/reason', 'code' => 'TEST']]], 'injected' => 'discard']);
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
        $this->api()->recall('patient-test', $this->resource()['id'], \App\Livewire\Referral\Forms\ServiceRequestForm::buildSignedEnvelope(['signed_data' => 'immutable-signed-content', 'status_reason' => 'outside', 'explanatory_letter' => 'outside']));
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
        $api = new \App\Classes\eHealth\Api\ServiceRequest($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();
        $response = $api->cancelUsage('request-test', 'patient-test', ['signed_data' => 'discard', 'explanatory_letter' => 'local explanation']);
        $this->assertSame('pending', $response->getData()['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/service_requests/request-test/actions/release') && $request->data() === []);
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
