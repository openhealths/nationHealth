<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth\Api\Patient;

use App\Classes\eHealth\Api\Patient\MedicationRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MedicationRequestRejectPayloadTest extends TestCase
{
    public function test_reject_keeps_reason_out_of_http_body_and_maps_signed_content(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'rejected']], 200)]);

        $api = $this->makeApi();
        $api->reject('person-uuid', 'mr-uuid', [
            'person_id' => 'person-uuid',
            'reject_reason_code' => 'entered-in-error',
            'reject_reason' => 'Помилка введення',
            'signed_content' => 'mock-signed-blob',
            'signed_content_encoding' => 'base64',
        ]);

        Http::assertSent(static function (Request $request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            if (!str_ends_with($path, '/api/medication_requests/mr-uuid/actions/reject')) {
                return false;
            }

            $data = $request->data();

            return ($data['signed_medication_reject'] ?? null) === 'mock-signed-blob'
                && ($data['signed_content_encoding'] ?? null) === 'base64'
                && !array_key_exists('signed_content', $data)
                && !array_key_exists('person_id', $data)
                && !array_key_exists('reject_reason_code', $data)
                && !array_key_exists('reject_reason', $data);
        });
    }

    /**
     * Http::fake() stubs live on the Factory; PendingRequest subclasses need stub transfer.
     */
    private function makeApi(): MedicationRequest
    {
        $factory = Http::getFacadeRoot();
        $api = new MedicationRequest($factory);

        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        return $api;
    }
}
