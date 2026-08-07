<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Classes\eHealth\Api\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeDeactivateApiTest extends TestCase
{
    #[Test]
    public function stopped_payload_includes_status_and_end_date(): void
    {
        Http::fake([
            '*/api/employees/*/actions/deactivate' => Http::response(['data' => ['id' => (string) Str::uuid()]], 200),
        ]);

        $api = $this->makeApi();
        $uuid = (string) Str::uuid();

        $api->deactivate($uuid, '2026-07-15', 'STOPPED');

        Http::assertSent(function ($request) use ($uuid) {
            return str_contains($request->url(), "/api/employees/{$uuid}/actions/deactivate")
                && $request->method() === 'PATCH'
                && $request['status'] === 'STOPPED'
                && $request['end_date'] === '2026-07-15';
        });
    }

    #[Test]
    public function entered_in_error_payload_omits_end_date(): void
    {
        Http::fake([
            '*/api/employees/*/actions/deactivate' => Http::response(['data' => ['id' => (string) Str::uuid()]], 200),
        ]);

        $api = $this->makeApi();
        $uuid = (string) Str::uuid();

        $api->deactivate($uuid, '2026-07-15', 'ENTERED_IN_ERROR');

        Http::assertSent(function ($request) use ($uuid) {
            $data = $request->data();

            return str_contains($request->url(), "/api/employees/{$uuid}/actions/deactivate")
                && $request->method() === 'PATCH'
                && ($data['status'] ?? null) === 'ENTERED_IN_ERROR'
                && !array_key_exists('end_date', $data);
        });
    }

    /**
     * Http::fake() stores stubs on the Factory; transfer them onto the PendingRequest subclass.
     */
    private function makeApi(): Employee
    {
        $factory = Http::getFacadeRoot();
        $api = new Employee($factory);

        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        return $api;
    }
}
