<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class MedicationRequestSignPayloadTest extends TestCase
{
    use DatabaseTransactions;

    private function detail(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/medication-request-sign-detail.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function prepare(array $response, int $httpStatus = 200): array
    {
        $native = $this->detail();
        $person = Person::create(['uuid' => $native['person']['id'], 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $record = MedicationRequestRequest::create(['uuid' => $native['id'], 'person_id' => $person->id, 'status' => 'NEW', 'ehealth_payload' => array_replace($native, ['seed' => 'stale-cache'])]);
        $encounter = new Encounter(['person_id' => $person->id]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => $response], $httpStatus)]);
        $factory = Http::getFacadeRoot();
        $api = new MedicationRequestApi($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $this->instance(MedicationRequestApi::class, $api);

        return [$encounter, $record];
    }

    public function test_sign_payload_is_exact_fresh_native_detail_even_when_cache_exists(): void
    {
        $native = $this->detail();
        [$encounter, $record] = $this->prepare($native);
        $method = new ReflectionMethod(MedicationRequestLifecycleService::class, 'buildSignPayload');
        $payload = $method->invoke(app(MedicationRequestLifecycleService::class), $encounter, $record, 'different-form-auth-method');
        $this->assertSame($native, $payload);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/api/persons/'.$native['person']['id'].'/medication_request_requests/'.$native['id']));
    }

    public function test_a_seed_returned_by_the_server_is_preserved_unchanged(): void
    {
        $native = array_replace($this->detail(), ['seed' => 'fresh-server-seed']);
        [$encounter, $record] = $this->prepare($native);
        $payload = (new ReflectionMethod(MedicationRequestLifecycleService::class, 'buildSignPayload'))
            ->invoke(app(MedicationRequestLifecycleService::class), $encounter, $record, '');

        $this->assertSame($native, $payload);
    }

    public function test_a_short_summary_with_valid_identity_and_seed_cannot_be_signed(): void
    {
        $native = $this->detail();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('medication-requests.signing_details_incomplete'));
        MedicationRequestApi::signableDetail([
            'id' => $native['id'],
            'person' => $native['person'],
            'status' => 'NEW',
            'seed' => 'fresh-server-seed',
        ], $native['person']['id'], $native['id']);
    }

    public static function invalidDetails(): array
    {
        return [
            'wrong identity' => ['id', '00000000-0000-4000-8000-000000000000'],
            'wrong patient' => ['person', ['id' => '00000000-0000-4000-8000-000000000000']],
            'not NEW' => ['status', 'SIGNED'],
            'invalid null seed' => ['seed', null],
            'empty seed' => ['seed', ''],
            'missing medication details' => ['medication_info', []],
            'missing dosage' => ['dosage_instruction', []],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_inconsistent_details_fail_before_signing(string $field, mixed $value): void
    {
        $native = $this->detail();
        $native[$field] = $value;
        [$encounter, $record] = $this->prepare($native);
        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(MedicationRequestLifecycleService::class, 'buildSignPayload'))->invoke(app(MedicationRequestLifecycleService::class), $encounter, $record, '');
    }

    public function test_failed_get_cannot_fall_back_to_cached_or_local_content(): void
    {
        [$encounter, $record] = $this->prepare([], 403);
        $this->expectException(\App\Exceptions\EHealth\EHealthResponseException::class);
        (new ReflectionMethod(MedicationRequestLifecycleService::class, 'buildSignPayload'))->invoke(app(MedicationRequestLifecycleService::class), $encounter, $record, '');
    }

    public static function unconfirmedTransitions(): array
    {
        return [[[]], [['status' => 'pending']], [['error' => ['message' => 'rejected']]], [['id' => '11111111-1111-4111-8111-111111111111', 'status' => 'processing']], [['status' => 'pending', 'data' => ['id' => '11111111-1111-4111-8111-111111111111', 'status' => 'ACTIVE']]]];
    }

    #[DataProvider('unconfirmedTransitions')]
    public function test_unconfirmed_mutation_cannot_advance_local_state(array $response): void
    {
        $this->expectException(RuntimeException::class);
        MedicationRequestApi::mutationResult($response, ['ACTIVE']);
    }
}
