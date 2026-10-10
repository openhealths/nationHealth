<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\Patient\MedicationDispense;
use App\Classes\eHealth\Api\Patient\MedicationRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\MedicationRequest\MedicationRequestIndex;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Services\SignatureService;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationDispenseMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_create_payload_keeps_the_old_array_and_json_contract(array $case, array $expected): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 09:00:00'));
        $requestApi = Mockery::mock(MedicationRequest::class)->makePartial();
        if ($case['participant'] !== null) {
            $requestApi->shouldReceive('post')->once()->with('/api/medication_requests/prescription/actions/qualify', [
                'division_id' => 'division', 'programs' => [['id' => 'program']],
            ])->andReturn($this->response([['participants' => [$case['participant']]]]));
        } else {
            $requestApi->shouldReceive('post')->never();
        }
        $this->instance(MedicationRequest::class, $requestApi);
        $api = Mockery::mock(MedicationDispense::class);
        $api->shouldReceive('create')->once()->withArgs(function (array $payload) use ($expected): bool {
            $this->assertSame($expected['payload'], $payload);
            $this->assertSame($expected['json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

            return true;
        })->andReturn($this->response(['id' => 'dispense', 'status' => 'PROCESSED']));
        $api->shouldReceive('process')->never();
        $this->instance(MedicationDispense::class, $api);
        $signature = Mockery::mock(SignatureService::class);
        $signature->shouldReceive('signData')->never();
        $this->instance(SignatureService::class, $signature);
        $component = new PharmacyMappingHarness();
        $component->medicationQty = $case['quantity'];
        $component->code = $case['code'];
        // Credentials and browser-added price keys must not enter the create payload.
        $component->form['password'] = 'secret';
        $component->form['sell_price'] = '9999';
        $employee = new Employee(['uuid' => 'employee']);
        $employee->setRelation('division', new Division(['uuid' => 'division']));

        $this->assertSame(['id' => 'dispense', 'status' => 'PROCESSED'], $component->dispense($case['request'], $employee));
    }

    public static function contracts(): iterable
    {
        $baseline = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Mapping/medication-dispense-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require __DIR__.'/../../Fixtures/Mapping/medication-dispense-inputs.php' as $name => $case) {
            yield $name => [$case, $baseline[$name]];
        }
    }

    private function response(array $data): EHealthResponse
    {
        return new EHealthResponse(new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)));
    }
}

class PharmacyMappingHarness extends MedicationRequestIndex
{
    public function dispense(array $request, Employee $employee): array
    {
        return $this->dispenseSelectedRequest($request, $employee);
    }
}
