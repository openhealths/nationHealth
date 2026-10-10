<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceRequestPrescribingTest extends TestCase
{
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
