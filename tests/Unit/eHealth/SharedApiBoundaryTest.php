<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth;

use App\Classes\eHealth\Api\Configuration;
use App\Classes\eHealth\Api\Dictionary;
use App\Classes\eHealth\Api\MedicalProgram;
use App\Classes\eHealth\Api\Patient as PatientApi;
use App\Classes\eHealth\Api\Patient\ClinicalImpression;
use App\Classes\eHealth\Api\Patient\Condition;
use App\Classes\eHealth\Api\Patient\DetectedIssue;
use App\Classes\eHealth\Api\Patient\Device;
use App\Classes\eHealth\Api\Patient\DeviceAssociation;
use App\Classes\eHealth\Api\Patient\DeviceDispense;
use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Classes\eHealth\Api\Patient\DiagnosticReport;
use App\Classes\eHealth\Api\Patient\Encounter;
use App\Classes\eHealth\Api\Patient\Episode;
use App\Classes\eHealth\Api\Patient\Immunization;
use App\Classes\eHealth\Api\Patient\Observation;
use App\Classes\eHealth\Api\Patient\Procedure;
use App\Classes\eHealth\Api\Patient\Specimen;
use App\Classes\eHealth\Api\Person;
use App\Classes\eHealth\EHealthRequest;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeClinicalFixtures as Native;
use Tests\TestCase;

class SharedApiBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
    }

    #[DataProvider('patientCollections')]
    public function test_every_clinical_collection_validates_before_adapting_keys(string $class): void
    {
        $api = $this->client($class, ['data' => [], 'paging' => Native::paging(1, 0, 0)]);
        $this->assertSame([], $api->getBySearchParams(Native::UUID)->validate());
        $api = $this->client($class, ['data' => [['id' => null]], 'paging' => Native::paging()]);
        $this->expectException(ValidationException::class);
        $api->getBySearchParams(Native::UUID)->validate();
    }

    public static function patientCollections(): array
    {
        return array_map(static fn (string $class): array => [$class], [
            ClinicalImpression::class, Condition::class, DetectedIssue::class, Device::class,
            DeviceAssociation::class, DeviceDispense::class, DeviceRequest::class, DiagnosticReport::class,
            Encounter::class, Episode::class, Immunization::class, Observation::class, Procedure::class, Specimen::class,
        ]);
    }

    #[DataProvider('summaryCollections')]
    public function test_summary_delegation_retains_a_response_validator(string $class, string $method): void
    {
        $api = $this->client($class, ['data' => [], 'paging' => Native::paging(1, 0, 0)]);
        $this->assertSame([], $api->$method(Native::UUID)->validate());
        $api = $this->client($class, ['data' => [['id' => null]], 'paging' => Native::paging()]);
        $this->expectException(ValidationException::class);
        $api->$method(Native::UUID)->validate();
    }

    public static function summaryCollections(): array
    {
        return [
            [ClinicalImpression::class, 'getSummary'], [Condition::class, 'getSummary'],
            [Device::class, 'getSummary'], [DiagnosticReport::class, 'getSummary'],
            [Immunization::class, 'getSummary'], [Observation::class, 'getSummary'],
            [Procedure::class, 'getSummary'], [Episode::class, 'getShortEpisodes'],
            [Encounter::class, 'getShortBySearchParams'], [PatientApi::class, 'getActiveDiagnoses'],
        ];
    }

    #[DataProvider('configurationMethods')]
    public function test_configuration_records_preserve_documented_settings_and_reject_missing_required_fields(string $method, string $operation, string $required): void
    {
        $body = $this->examples()[$operation];
        $api = $this->client(Configuration::class, $body);
        $validated = $api->$method()->validate();
        if ($method === 'getCompositions') {
            foreach ($validated as $index => &$row) {
                $this->assertSame(json_decode($body['data'][$index]['settings'], true, flags: JSON_THROW_ON_ERROR), $row['decoded_settings']);
                unset($row['decoded_settings']);
            }
            unset($row);
        }
        $this->assertSame($body['data'], $validated);
        unset($body['data'][0][$required]);
        $api = $this->client(Configuration::class, $body);
        $this->expectException(ValidationException::class);
        $api->$method()->validate();
    }

    public static function configurationMethods(): array
    {
        return [
            ['getMetadata', 'GET /api/configurations/metadata', 'updated_at'],
            ['getCompositions', 'GET /api/composition_configurations', 'settings'],
            ['getDevices', 'GET /api/devices_configurations', 'settings'],
            ['getDeviceParameters', 'GET /api/device_parameters_configurations', 'settings'],
            ['getDictionaries', 'GET /api/dictionaries_configurations', 'settings'],
            ['getObservations', 'GET /api/observation_configurations', 'settings'],
        ];
    }

    public function test_native_device_quantityless_code_is_allowed_only_without_a_program_or_model(): void
    {
        $record = Native::deviceRequest(Native::UUID, Native::UUID);
        unset($record['quantity'], $record['remaining_quantity']);
        $api = $this->client(DeviceRequest::class, ['data' => $record]);
        $this->assertSame($record, $api->getById(Native::UUID, Native::UUID)->validate());
        foreach (['program' => Native::reference('medical_program'), 'code_reference' => Native::reference('device_definition')] as $field => $reference) {
            $invalid = $record + [$field => $reference];
            try {
                $this->client(DeviceRequest::class, ['data' => $invalid])->getById(Native::UUID, Native::UUID)->validate();
                $this->fail('A program or model must retain quantity requirements.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_official_episode_example_and_ongoing_state_preserve_optional_references(): void
    {
        $body = $this->examples()['GET /api/patients/{patient_id}/episodes/{episode_id}'];
        $valid = $this->client(Episode::class, $body)->getById(Native::UUID, $body['data']['id'])->validate();
        $this->assertSame($body['data']['id'], $valid['uuid']);
        $this->assertSame($body['data']['status_history'][0]['status reason'], $valid['status_history'][0]['status_reason']);
        $this->assertSame($body['data']['status_history'][0]['inserted_at'], $valid['status_history'][0]['ehealth_inserted_at']);

        unset($body['data']['status_reason'], $body['data']['status_history'], $body['data']['period']['end'], $body['data']['closing_summary'], $body['data']['explanatory_letter']);
        $ongoing = $this->client(Episode::class, $body)->getById(Native::UUID, $body['data']['id'])->validate();
        $this->assertSame('active', $ongoing['status']);
        $this->assertArrayNotHasKey('status_reason', $ongoing);
    }

    #[DataProvider('invalidOngoingEpisodes')]
    public function test_episode_ongoing_alternative_retains_full_fields_and_terminal_requirements(string $case): void
    {
        $body = $this->examples()['GET /api/patients/{patient_id}/episodes/{episode_id}'];
        unset($body['data']['status_reason'], $body['data']['status_history'], $body['data']['period']['end'], $body['data']['closing_summary'], $body['data']['explanatory_letter']);
        match ($case) {
            'closed' => $body['data']['status'] = 'closed',
            'cancelled' => $body['data']['status'] = 'entered-in-error',
            'end' => $body['data']['period']['end'] = '2026-11-11T10:00:00Z',
            'reason' => $body['data']['status_reason'] = ['coding' => [['code' => 'cured']]],
            'history' => $body['data']['status_history'] = [['status' => 'active']],
            'period' => $body['data']['period'] = [],
        };
        $this->expectException(ValidationException::class);
        $this->client(Episode::class, $body)->getById(Native::UUID, $body['data']['id'])->validate();
    }

    public static function invalidOngoingEpisodes(): array
    {
        return [['closed'], ['cancelled'], ['end'], ['reason'], ['history'], ['period']];
    }

    public function test_dictionary_hierarchy_rejects_a_malformed_nested_label_before_flattening(): void
    {
        $body = $this->examples()['GET /api/v2/dictionaries'];
        $api = $this->client(Dictionary::class, $body);
        $this->assertSame($body['data'], $api->getMany()->validate());
        $body['data'][0]['values'][0]['child_values'][0]['is_active'] = 'yes';
        $this->expectException(ValidationException::class);
        $this->client(Dictionary::class, $body)->getMany()->validate();
    }

    public function test_authentication_method_invalid_flag_is_rejected_before_selection(): void
    {
        $body = $this->examples()['GET /api/persons/{id}/authentication_methods'];
        $valid = $this->client(Person::class, $body)->getAuthMethods(Native::UUID)->validate();
        $this->assertSame($body['data'][0]['id'], $valid[0]['uuid']);
        $body['data'][0]['is_active'] = 'yes';
        $this->expectException(ValidationException::class);
        $this->client(Person::class, $body)->getAuthMethods(Native::UUID)->validate();
    }

    public function test_medical_program_settings_are_not_truncated_and_reject_an_invalid_rule(): void
    {
        $body = $this->examples()['GET /api/medical_programs'];
        $this->assertSame($body['data'], $this->client(MedicalProgram::class, $body)->getMany()->validate());
        $body['data'][0]['medical_program_settings']['device_request_allowed_code_types'] = ['undocumented'];
        $this->expectException(ValidationException::class);
        $this->client(MedicalProgram::class, $body)->getMany()->validate();
    }

    #[DataProvider('invalidPaging')]
    public function test_paging_is_validated_before_a_caller_can_continue(?array $paging): void
    {
        $body = ['data' => []];
        if ($paging !== null) {
            $body['paging'] = $paging;
        }
        $this->expectException(ValidationException::class);
        $this->client(DeviceRequest::class, $body)->getBySearchParams(Native::UUID)->validate();
    }

    public static function invalidPaging(): array
    {
        return [
            [null], [['page' => 1]], [Native::paging() + ['other' => true]],
            [array_replace(Native::paging(), ['page' => 2])],
            [array_replace(Native::paging(), ['total_pages' => '2'])],
        ];
    }

    #[DataProvider('invalidPrequalify')]
    public function test_incomplete_or_unknown_prequalify_result_never_confirms_eligibility(array $data): void
    {
        $this->expectException(EHealthValidationException::class);
        app(EHealthJobResolver::class)->assertPrequalifyValid($data);
    }

    public static function invalidPrequalify(): array
    {
        return [
            [[]], [['status' => 'VALID']], [[['status' => 'VALID']]],
            [[['program_id' => Native::UUID, 'program_name' => 'Program', 'status' => 'UNKNOWN']]],
            [[['program_id' => Native::UUID, 'program_name' => 'Program', 'status' => 'INVALID', 'rejection_reason' => 'Rejected']]],
        ];
    }

    #[DataProvider('invalidJobs')]
    public function test_device_submission_rejects_untrusted_job_receipts(array $data): void
    {
        $this->expectException(ValidationException::class);
        $this->client(DeviceRequest::class, ['data' => $data])->createSigned(Native::UUID, [])->validate();
    }

    public static function invalidJobs(): array
    {
        return [
            [['status' => 'invented', 'links' => [['entity' => 'job', 'href' => '/api/jobs/test']]]],
            [['status' => 'pending', 'links' => [['entity' => 'device_request', 'href' => '/api/jobs/test']]]],
            [['status' => 'pending', 'links' => [['entity' => 'job', 'href' => 'https://evil.test/api/jobs/test']]]],
            [['status' => 'pending', 'links' => [['entity' => 'job', 'href' => '/api/jobs/../device_requests']]]],
        ];
    }

    private function examples(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/shared-official-responses.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function client(string $class, array $body): EHealthRequest
    {
        $factory = new Factory();
        $factory->fake(['ehealth.test/*' => Http::response($body)]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new $class($factory);
        $api->stub($stubs)->preventStrayRequests();

        return $api;
    }
}
