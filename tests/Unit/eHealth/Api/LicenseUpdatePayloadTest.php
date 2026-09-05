<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth\Api;

use App\Classes\eHealth\Api\License;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseUpdatePayloadTest extends TestCase
{
    public function test_update_sends_empty_string_when_license_number_is_cleared(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->licenseResponse(['license_number' => ''])], 200)]);

        $this->makeApi()->update('license-uuid', [
            'type' => 'MSP',
            'licenseNumber' => '',
            'issuedBy' => 'Комісія',
            'issuedDate' => '01.02.2024',
            'expiryDate' => '01.02.2027',
            'activeFromDate' => '01.02.2024',
            'whatLicensed' => 'діяльність',
            'orderNo' => 'ВА1',
            'isPrimary' => false,
        ]);

        Http::assertSent(static function (Request $request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            if (!str_ends_with($path, '/api/licenses/license-uuid')) {
                return false;
            }

            $data = $request->data();

            return array_key_exists('license_number', $data)
                && $data['license_number'] === ''
                && ($data['type'] ?? null) === 'MSP'
                && ($data['order_no'] ?? null) === 'ВА1';
        });
    }

    public function test_update_keeps_license_number_when_provided(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->licenseResponse()], 200)]);

        $this->makeApi()->update('license-uuid', [
            'type' => 'MSP',
            'licenseNumber' => 'AB-123',
            'issuedBy' => 'Комісія',
            'issuedDate' => '01.02.2024',
            'expiryDate' => '01.02.2027',
            'activeFromDate' => '01.02.2024',
            'whatLicensed' => 'діяльність',
            'orderNo' => 'ВА1',
            'isPrimary' => false,
        ]);

        Http::assertSent(static function (Request $request): bool {
            $data = $request->data();

            return ($data['license_number'] ?? null) === 'AB-123';
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function licenseResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'license-uuid',
            'type' => 'MSP',
            'license_number' => 'AB-123',
            'issued_by' => 'Комісія',
            'issued_date' => '2024-02-01',
            'expiry_date' => '2027-02-01',
            'active_from_date' => '2024-02-01',
            'what_licensed' => 'діяльність',
            'order_no' => 'ВА1',
            'legal_entity_id' => '00000000-0000-0000-0000-000000000001',
            'is_primary' => false,
            'is_active' => true,
            'inserted_at' => '2024-02-01T00:00:00.000Z',
            'inserted_by' => '00000000-0000-0000-0000-000000000002',
            'updated_at' => '2024-02-01T00:00:00.000Z',
            'updated_by' => '00000000-0000-0000-0000-000000000002',
        ], $overrides);
    }

    /**
     * Http::fake() stubs live on the Factory; PendingRequest subclasses need stub transfer.
     */
    private function makeApi(): License
    {
        $factory = Http::getFacadeRoot();
        $api = new License($factory);

        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        return $api;
    }
}
