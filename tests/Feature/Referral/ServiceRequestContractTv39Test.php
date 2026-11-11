<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\Api\Service;
use App\Classes\eHealth\EHealthResponse;
use App\Jobs\UpdateDictionaryCache;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ServiceRequestContractTv39Test extends TestCase
{
    private const string UUID = '10050000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ehealth.api.domain', 'https://ehealth.invalid');
        Http::preventStrayRequests();
    }

    private function nativeApi(): ServiceRequest
    {
        $factory = Http::getFacadeRoot();
        $api = new ServiceRequest($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();

        return $api;
    }

    private function resource(): array
    {
        $reference = ['display_value' => '', 'identifier' => ['value' => self::UUID, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'patient']]]]];

        return ['id' => self::UUID, 'requisition' => '1234-5678-9012-3456', 'status' => 'active', 'status_history' => [], 'intent' => 'order', 'category' => ['coding' => [['system' => 'eHealth/SNOMED/service_request_categories', 'code' => 'laboratory_procedure']]], 'code' => $reference, 'subject' => $reference, 'requester_employee' => $reference, 'requester_legal_entity' => $reference, 'reason_reference' => [], 'note' => '', 'patient_instruction' => '', 'expiration_date' => '2027-11-11T00:00:00Z', 'used_by_employee' => ['display_value' => ''], 'used_by_legal_entity' => ['display_value' => ''], 'occurrence_period' => ['start' => '2026-11-11T00:00:00Z'], 'identity' => ['last_name' => 'Тест', 'first_name' => 'Пацієнт', 'second_name' => null, 'gender' => 'MALE', 'age' => 1]];
    }

    private function response(array $data): EHealthResponse
    {
        return new EHealthResponse(new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)));
    }

    public function test_complete_detail_contract_preserves_extension_without_patient_enrichment(): void
    {
        $data = $this->resource() + ['extension' => ['false' => false, 'empty' => []]];
        $this->assertSame($data, ServiceRequest::validateResourceData($data));
        $this->assertSame([$data], (new ReflectionMethod(ServiceRequest::class, 'validateMany'))->invoke(new ServiceRequest(), $this->response([$data])));
    }

    public static function malformedFields(): array
    {
        return array_map(static fn (string $field): array => [$field], ['id', 'requisition', 'status', 'status_history', 'intent', 'category', 'code', 'subject', 'requester_employee', 'requester_legal_entity', 'reason_reference', 'note', 'patient_instruction', 'expiration_date', 'used_by_employee', 'used_by_legal_entity', 'occurrence_period']);
    }

    #[DataProvider('malformedFields')]
    public function test_required_server_fields_cannot_be_consumed_when_missing(string $field): void
    {
        $data = $this->resource();
        unset($data[$field]);
        $this->expectException(ValidationException::class);
        ServiceRequest::validateResourceData($data);
    }

    public function test_every_list_entry_and_identity_is_validated(): void
    {
        $bad = $this->resource();
        unset($bad['identity']['age']);
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(ServiceRequest::class, 'validateMany'))->invoke(new ServiceRequest(), $this->response([$this->resource(), $bad]));
    }

    public function test_two_occurrence_representations_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        ServiceRequest::validateResourceData($this->resource() + ['occurrence_date_time' => '2026-11-11T00:00:00Z']);
    }

    public function test_nested_reference_and_quantity_are_validated(): void
    {
        $data = $this->resource();
        $data['focus'] = [['identifier' => ['value' => 'broken'], 'display_value' => '']];
        $this->expectException(ValidationException::class);
        ServiceRequest::validateResourceData($data);
    }

    public function test_specimen_list_does_not_require_or_project_identity(): void
    {
        $data = $this->resource();
        unset($data['identity']);
        $this->assertSame([$data], (new ReflectionMethod(ServiceRequest::class, 'validateMany'))->invoke(new ServiceRequest(), $this->response([$data]), false));
    }

    public function test_patient_list_endpoint_validates_without_requisition_identity(): void
    {
        $data = $this->resource();
        unset($data['identity']);
        Http::fake(['*' => Http::response(['data' => [$data]], 200)]);
        $this->assertSame([$data], $this->nativeApi()->getBySearchParams(self::UUID)->validate());
    }

    public function test_requisition_endpoint_accepts_nullable_names_but_requires_identity(): void
    {
        $data = $this->resource();
        $data['identity']['first_name'] = null;
        $data['identity']['last_name'] = null;
        $withoutIdentity = $data;
        unset($withoutIdentity['identity']);
        Http::fake(['*' => Http::sequence()->push(['data' => [$data]])->push(['data' => [$withoutIdentity]])]);
        $this->assertSame([$data], $this->nativeApi()->searchForServiceRequestsByParams(['requisition' => $data['requisition']])->validate());
        $this->expectException(ValidationException::class);
        $this->nativeApi()->searchForServiceRequestsByParams(['requisition' => $data['requisition']])->validate();
    }

    public function test_patient_list_endpoint_still_rejects_malformed_native_resource(): void
    {
        $data = $this->resource();
        unset($data['identity'], $data['subject']);
        Http::fake(['*' => Http::response(['data' => [$data]], 200)]);
        $this->expectException(ValidationException::class);
        $this->nativeApi()->getBySearchParams(self::UUID)->validate();
    }

    public function test_qualification_requires_complete_program_verdict(): void
    {
        $this->expectException(ValidationException::class);
        ServiceRequest::validateQualification($this->response([['status' => 'VALID']]));
    }

    public function test_nested_malformed_service_is_rejected_before_catalog_use(): void
    {
        $node = ['id' => self::UUID, 'name' => 'Group', 'code' => 'TEST', 'is_active' => true, 'request_allowed' => true, 'inserted_at' => '2026-11-11T00:00:00Z', 'updated_at' => '2026-11-11T00:00:00Z', 'inserted_by' => self::UUID, 'updated_by' => self::UUID, 'services' => [['id' => self::UUID, 'name' => 'Broken']]];
        $this->expectException(ValidationException::class);
        Service::validateCatalog($this->response($node));
    }

    public function test_missing_list_envelope_is_not_an_empty_success(): void
    {
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(ServiceRequest::class, 'validateMany'))->invoke(new ServiceRequest(), new EHealthResponse(new Response(200, [], '{}')));
    }

    public function test_malformed_paging_is_rejected_before_iteration(): void
    {
        $response = new EHealthResponse(new Response(200, [], json_encode(['data' => [$this->resource()], 'paging' => ['page' => 1, 'page_size' => 100, 'total_pages' => 'broken', 'total_entries' => 1]], JSON_THROW_ON_ERROR)));
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(ServiceRequest::class, 'validateMany'))->invoke(new ServiceRequest(), $response);
    }

    public function test_malformed_status_history_coding_is_rejected(): void
    {
        $data = $this->resource();
        $data['status_history'] = [['status' => 'active', 'inserted_at' => '2026-11-11T00:00:00Z', 'status_reason' => ['coding' => [['system' => 'TEST', 'code' => []]]]]];
        $this->expectException(ValidationException::class);
        ServiceRequest::validateResourceData($data);
    }

    public function test_missing_catalog_envelope_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        Service::validateCatalog(new EHealthResponse(new Response(200, [], '{}')));
    }
    public function test_malformed_catalog_never_enters_the_initial_dictionary_cache(): void
    {
        Cache::forget(ServiceDictionary::KEY);
        Cache::forget(ServiceDictionary::KEY.':fresh');
        $response = new EHealthResponse(new Response(200, [], '{"data":[{"id":"broken"}]}'), Service::validateCatalog(...));
        $dictionary = Mockery::mock(ServiceDictionary::class)->makePartial();
        $dictionary->shouldReceive('fetch')->once()->andReturn($response);
        $manager = new DictionaryManager();
        $manager->register($dictionary);
        try {
            $manager->services();
            $this->fail('Malformed catalog data must not become a dictionary.');
        } catch (ValidationException) {
            $this->assertFalse(Cache::has(ServiceDictionary::KEY));
            $this->assertFalse(Cache::has(ServiceDictionary::KEY.':fresh'));
        }
    }

    public function test_malformed_background_catalog_preserves_the_last_validated_cache(): void
    {
        $lastValidated = [['id' => self::UUID, 'name' => 'Retained']];
        Cache::put(ServiceDictionary::KEY, $lastValidated);
        Cache::forget(ServiceDictionary::KEY.':fresh');
        $response = new EHealthResponse(new Response(200, [], '{"data":[{"id":"broken"}]}'), Service::validateCatalog(...));
        $manager = Mockery::mock(DictionaryManager::class);
        $manager->shouldReceive('fetchPage')->once()->with(ServiceDictionary::KEY, 1)->andReturn($response);
        try {
            (new UpdateDictionaryCache(ServiceDictionary::KEY))->handle($manager);
            $this->fail('A malformed refresh must not replace the validated cache.');
        } catch (ValidationException) {
            $this->assertSame($lastValidated, Cache::get(ServiceDictionary::KEY));
            $this->assertFalse(Cache::has(ServiceDictionary::KEY.':fresh'));
        }
    }

    public function test_resend_sms_validates_the_acknowledgement_before_returning_success(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['data' => 'Malformed acknowledgement.']));
        $api = Mockery::mock(ServiceRequest::class);
        $api->shouldReceive('resendSms')->once()->with(self::UUID, self::UUID)->andReturn($response);
        $this->instance(ServiceRequest::class, $api);
        $this->expectException(ValidationException::class);

        app(ReferralRequestLifecycleService::class)->resendSms(self::UUID, self::UUID, 'service_request');
    }

}
