<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth;

use App\Classes\eHealth\Api\Patient\MedicationRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\MedicationResponseContract;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MedicationResponseContractTest extends TestCase
{
    public function test_official_examples_validate_for_every_consumed_operation(): void
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $operation => $data) {
            $this->assertSame($data, MedicationResponseContract::validate($this->response(['data' => $data]), $operation), $operation);
        }
    }

    public function test_incomplete_or_malformed_native_detail_cannot_be_consumed(): void
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['person', 'employee', 'medication_info', 'dosage_instruction', 'context', 'started_at', 'status'] as $field) {
            $data = $fixtures['detail'];
            unset($data[$field]);
            try {
                MedicationResponseContract::validate($this->response(['data' => $data]), 'detail');
                $this->fail('Missing '.$field.' was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.'.$field, $exception->errors());
            }
        }
        $data = $fixtures['detail'];
        $data['medication_info']['medication_qty'] = '1';
        $this->expectException(ValidationException::class);
        MedicationResponseContract::validate($this->response(['data' => $data]), 'detail');
    }

    public function test_non_list_items_and_invalid_paging_are_rejected(): void
    {
        foreach ([['data' => ['broken' => 'row']], ['data' => [null]], ['data' => [], 'paging' => ['page_number' => '2']]] as $envelope) {
            try {
                MedicationResponseContract::validate($this->response($envelope), 'list');
                $this->fail('Malformed list was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_resend_uses_documented_patch_and_retains_validation(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'bad', 'status' => 'ACTIVE']], 200)]);
        $factory = Http::getFacadeRoot();
        $api = new MedicationRequest($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $response = $api->resendOtp('patient-id', 'prescription-id');
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/medication_requests/prescription-id/actions/resend'));
        $this->expectException(ValidationException::class);
        $response->validate();
    }

    private function response(array $body): EHealthResponse
    {
        return new EHealthResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }
}
