<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth\Api;

use App\Classes\eHealth\Api\License;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdditionalLicenseActivityPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.date_format' => 'd.m.Y']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => []])]);
    }

    #[DataProvider('providedActivities')]
    public function test_api_preserves_required_activity_in_the_outgoing_payload(string $operation, string $key, string $value): void
    {
        $this->send($operation, [$key => $value]);

        Http::assertSent(static fn (Request $request): bool =>
            $request->method() === ($operation === 'create' ? 'POST' : 'PATCH')
            && ($request->data()['what_licensed'] ?? null) === $value
            && ($request->data()['type'] ?? null) === 'PHARMACY_DRUGS');
    }

    public static function providedActivities(): array
    {
        $cases = [];
        foreach (['create', 'update'] as $operation) {
            foreach (['whatLicensed', 'what_licensed'] as $key) {
                foreach (['Діяльність за ліцензією', '0'] as $value) {
                    $cases[$operation . ' ' . $key . ' ' . $value] = [$operation, $key, $value];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidActivities')]
    public function test_api_does_not_send_a_request_without_valid_activity(string $operation, array $activity): void
    {
        try {
            $this->send($operation, $activity);
            $this->fail('An invalid required activity must prevent the HTTP request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('what_licensed', $exception->errors());
        }

        Http::assertNothingSent();
    }

    public static function invalidActivities(): array
    {
        $cases = [];
        $activities = [
            'missing' => [],
            'null' => ['whatLicensed' => null],
            'empty' => ['whatLicensed' => ''],
            'whitespace' => ['whatLicensed' => " \t\n"],
            'integer' => ['whatLicensed' => 123],
            'array' => ['whatLicensed' => []],
            'overlong' => ['whatLicensed' => str_repeat('А', 256)],
            'snake case null' => ['what_licensed' => null],
            'snake case empty' => ['what_licensed' => ''],
        ];
        foreach (['create', 'update'] as $operation) {
            foreach ($activities as $name => $activity) {
                $cases[$operation . ' ' . $name] = [$operation, $activity];
            }
        }

        return $cases;
    }

    private function send(string $operation, array $activity): void
    {
        $factory = Http::getFacadeRoot();
        $api = new License($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);
        $data = $activity + [
            'type' => 'PHARMACY_DRUGS',
            'issuedBy' => 'МОЗ',
            'issuedDate' => '01.01.2025',
            'activeFromDate' => '01.01.2025',
            'expiryDate' => now()->addYear()->format('d.m.Y'),
            'orderNo' => '123',
            'isPrimary' => false,
        ];

        if ($operation === 'create') {
            $api->create($data);
        } else {
            $api->update('additional-license-uuid', $data);
        }
    }
}
