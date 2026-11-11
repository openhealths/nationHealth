<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\MedicalProgram;
use App\Classes\eHealth\Api\Patient\DeviceRequest;

use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceRequestPrescribingTest extends TestCase
{
    public function test_all_parameter_types_preserve_their_ehealth_value_types(): void
    {
        $definitions = [];
        foreach (['boolean', 'string', 'codeable_concept', 'quantity', 'range'] as $type) {
            $definitions[$type] = ['system' => 'device_request_code_parameter', 'code' => $type, 'type' => $type,
                'required' => true, 'allowed' => [], 'dictionary' => 'test', 'values' => ['selected' => 'Значення']];
        }
        $quantity = ['value' => '12.5', 'code' => 'cm'];
        $parameters = app(DeviceRequestLifecycleService::class)->parameters([
            'boolean' => '0', 'string' => 'Опис', 'codeable_concept' => 'selected',
            'quantity' => $quantity, 'range' => ['low' => $quantity, 'high' => array_replace($quantity, ['value' => '20'])],
        ], $definitions);

        $this->assertFalse($parameters[0]['value_boolean']);
        $this->assertSame('Опис', $parameters[1]['value_string']);
        $this->assertSame('selected', $parameters[2]['value_codeable_concept']['coding'][0]['code']);
        $this->assertSame(12.5, $parameters[3]['value_quantity']['value']);
        $this->assertSame(20, $parameters[4]['value_range']['high']['value']);
        $this->assertSame('eHealth/ucum/units', $parameters[4]['value_range']['low']['system']);
    }

    public function test_empty_optional_complex_parameters_are_omitted(): void
    {
        $this->assertSame([], app(DeviceRequestLifecycleService::class)->parameters(['size' => ['value' => '', 'code' => '', 'system' => '']], [
            'size' => ['system' => 'test', 'code' => 'size', 'type' => 'quantity', 'required' => false],
        ]));
    }

    public function test_invalid_complex_parameter_reports_the_exact_form_field_in_ukrainian(): void
    {
        try {
            app(DeviceRequestLifecycleService::class)->parameters(['test|size' => ['value' => 'abc', 'code' => 'cm', 'system' => 'UCUM']], [
                'test|size' => ['system' => 'test', 'code' => 'size', 'type' => 'quantity', 'required' => true, 'allowed' => []],
            ]);
            $this->fail('A non-numeric quantity must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parameter_values.test|size.value', $exception->errors());
            $this->assertStringContainsString('Значення', $exception->getMessage());
            $this->assertStringNotContainsString('test|size', $exception->getMessage());
        }
    }

    public function test_range_cannot_use_different_units_for_its_bounds(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->parameters(['size' => [
            'low' => ['value' => '1', 'code' => 'cm', 'system' => 'UCUM'],
            'high' => ['value' => '20', 'code' => 'kg', 'system' => 'UCUM'],
        ]], ['size' => ['system' => 'test', 'code' => 'size', 'type' => 'range', 'required' => true, 'allowed' => []]]);
    }

    public function test_display_dates_are_normalized_and_impossible_dates_are_rejected(): void
    {
        $service = app(DeviceRequestLifecycleService::class);
        $normalized = $service->normalizeOccurrenceDates(['started_at' => today()->format('d.m.Y'), 'ended_at' => today()->addDays(3)->format('d.m.Y')]);
        $this->assertSame(today()->toDateString(), $normalized['started_at']);
        $this->assertSame(today()->addDays(3)->toDateString(), $normalized['ended_at']);
        $this->expectException(ValidationException::class);
        $service->normalizeOccurrenceDates(['started_at' => '31.02.2099', 'ended_at' => '01.03.2099']);
    }

    public function test_fresh_medical_program_details_use_mis_credentials_without_changing_user_token(): void
    {
        $programId = (string) Str::uuid();
        config(['ehealth.api.domain' => 'https://ehealth.example', 'ehealth.api.mis_token' => 'mis-token',
            'ehealth.api.mis_api_key' => 'mis-api-key', 'ehealth.api.oauth.bearer_token' => 'userBearer']);
        session()->put('userBearer', 'doctor-token');
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/shared-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['GET /api/medical_programs/id'];
        $fixture['data']['id'] = $programId;
        $fixture['data']['type'] = 'DEVICE';
        Http::fake(['*' => Http::response($fixture, 200)]);
        $factory = Http::getFacadeRoot();
        $api = new MedicalProgram($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $api->preventStrayRequests();
        $this->instance(MedicalProgram::class, $api);
        $program = app(DeviceRequestLifecycleService::class)->program($programId);
        $this->assertSame($programId, $program['id']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ehealth.example/api/medical_programs/'.$programId
            && $request->hasHeader('Authorization', 'Bearer mis-token') && $request->hasHeader('API-key', 'mis-api-key'));
        $this->assertSame('doctor-token', session('userBearer'));
    }

    public function test_quantity_may_match_any_available_packaging_variant(): void
    {
        $service = app(DeviceRequestLifecycleService::class);
        $packages = [['count' => 30, 'unit' => 'pcs'], ['count' => 50, 'unit' => 'pcs']];
        $service->validateQuantity(100, 'pcs', true, $packages, 200, 20, CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-19'));
        $this->addToAssertionCount(1);
    }

    public function test_quantity_is_checked_against_current_remote_remaining_quantity(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->validateQuantity(100, 'pcs', true, [['count' => 50, 'unit' => 'pcs']], 50, null, CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-19'));
    }

    public function test_program_device_daily_limit_is_enforced_for_the_selected_package(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->validateQuantity(100, 'pcs', true, [['count' => 50, 'unit' => 'pcs', 'maxDailyCount' => 5]], null, null, CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-19'));
    }

    public function test_quantity_must_match_the_packaging_unit(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->validateQuantity(100, 'pair', true, [['count' => 50, 'unit' => 'pcs']], null, null, CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-19'));
    }

    public function test_quantity_without_program_can_be_omitted(): void
    {
        app(DeviceRequestLifecycleService::class)->validateQuantity(null, '', false, [], null, null, CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-19'));
        $this->addToAssertionCount(1);
    }

    public function test_program_requires_quantity(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->validateQuantity(null, '', true, [], null, null, CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-19'));
    }

    public function test_false_is_a_present_required_boolean_parameter(): void
    {
        $definition = ['system' => 'device_request_code_parameter', 'code' => 'brakes', 'type' => 'boolean', 'required' => true, 'allowed' => [], 'values' => []];
        $parameters = app(DeviceRequestLifecycleService::class)->parameters(['brakes' => '0'], ['brakes' => $definition]);
        $this->assertFalse($parameters[0]['value_boolean']);
    }

    public function test_required_parameter_cannot_be_omitted(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->parameters([], ['brakes' => ['system' => 'device_request_code_parameter', 'code' => 'brakes', 'type' => 'boolean', 'required' => true, 'allowed' => [], 'values' => []]]);
    }

    public function test_false_boolean_is_allowed_by_a_boolean_configuration(): void
    {
        $definition = ['system' => 'device_request_code_parameter', 'code' => 'brakes', 'type' => 'boolean', 'required' => true, 'allowed' => [false], 'values' => []];
        $parameters = app(DeviceRequestLifecycleService::class)->parameters(['brakes' => '0'], ['brakes' => $definition]);
        $this->assertFalse($parameters[0]['value_boolean']);
    }

    public function test_parameter_removed_by_configuration_refresh_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->parameters(['removed' => 'yes'], []);
    }

    public function test_dictionary_parameter_cannot_use_an_unavailable_value(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->parameters(['height' => 'xl'], ['height' => ['system' => 'device_request_code_parameter', 'code' => 'height', 'type' => 'codeable_concept', 'required' => true, 'dictionary' => 'height_values', 'allowed' => ['s'], 'values' => ['s' => 'Малий']]]);
    }

    public function test_request_with_quantity_cannot_be_manually_completed(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->assertTransition(['status' => 'active', 'quantity' => ['value' => 50]], 'complete');
    }

    public function test_request_without_quantity_can_be_completed(): void
    {
        app(DeviceRequestLifecycleService::class)->assertTransition(['status' => 'active'], 'complete');
        $this->addToAssertionCount(1);
    }

    public function test_revoked_request_cannot_be_revoked_again(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->assertTransition(['status' => 'revoked'], 'revoke');
    }

    public function test_device_actions_use_patient_context_and_patch_contract(): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.example']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['status' => 'completed']], 200)]);
        $factory = Http::getFacadeRoot();
        $api = new DeviceRequest($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $api->preventStrayRequests();
        $api->complete('patient', 'request');
        $api->revoke('patient', 'request', ['signed_data' => 'PKCS7']);
        $api->markInError('patient', 'request', ['signed_data' => 'PKCS7']);
        $api->resendSms('patient', 'request');
        Http::assertSentCount(4);
        foreach (['complete', 'revoke', 'mark_in_error', 'resend'] as $action) {
            Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request->url() === 'https://ehealth.example/api/patients/patient/device_requests/request/actions/'.$action);
        }
    }

    public function test_offline_program_message_requires_printing_the_redemption_code(): void
    {
        $message = app(DeviceRequestLifecycleService::class)->createdMessage('ABCD-1234-5678-9012', true, 'OFFLINE');
        $this->assertStringContainsString('ABCD-1234-5678-9012', $message);
        $this->assertStringContainsString('обов`язково роздрукувати', $message);
    }
}
