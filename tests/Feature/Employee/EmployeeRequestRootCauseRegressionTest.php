<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Events\EHealthUserLogin;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Enums\Status;
use App\Listeners\eHealth\EmployeeCreate;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\Revision;
use App\Models\User;
use App\Repositories\EmployeeRepository;
use App\Services\Employee\EmployeeRequestMatcher;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeRequestRootCauseRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
    }

    public function test_login_must_not_select_a_new_request_for_another_division(): void
    {
        $party = ['tax_id' => '1234567890', 'first_name' => 'Тест', 'last_name' => 'Посада', 'second_name' => null];
        $first = $this->newPositionRequest(101, 'division-a', '2024-01-01 12:00:00', $party);
        $second = $this->newPositionRequest(102, 'division-b', '2024-01-02 12:00:00', $party);
        $remote = [
            'uuid' => 'employee-a', 'employee_type' => 'SPECIALIST', 'position' => 'P8', 'start_date' => '2024-01-01',
            'division_id' => 'division-a', 'party' => $party,
        ];
        $method = new ReflectionMethod(EmployeeCreate::class, 'findMatchingLocalRequest');
        $match = $method->invoke(new EmployeeCreate(), collect([$first, $second]), $remote);

        $this->assertSame(101, $match?->id, 'The request for division B must not supply specialities to employee A.');
    }

    public function test_linked_draft_remains_editable_after_canonical_party_relink(): void
    {
        $type = LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT']);
        $legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id, 'is_active' => true]);
        $partyData = ['first_name' => 'Тест', 'last_name' => 'Особа', 'tax_id' => '1234567890', 'birth_date' => '1990-01-01', 'gender' => 'MALE'];
        $oldParty = Party::create(['uuid' => (string) Str::uuid()] + $partyData);
        $canonicalParty = Party::create(['uuid' => (string) Str::uuid()] + $partyData);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id, 'party_id' => $oldParty->id,
            'employee_type' => 'SPECIALIST', 'position' => 'P8', 'status' => Status::APPROVED, 'start_date' => '2024-01-01', 'is_active' => true,
        ]);
        $request = EmployeeRequest::create([
            'legal_entity_id' => $legalEntity->id, 'party_id' => $oldParty->id, 'employee_id' => $employee->id,
            'employee_type' => 'SPECIALIST', 'position' => 'P8', 'status' => RequestStatus::NEW,
        ]);
        $request->revision()->create([
            'status' => RevisionStatus::PENDING,
            'data' => ['employee_uuid' => $employee->uuid, 'employee_request_data' => ['employee_type' => 'SPECIALIST', 'position' => 'P8']],
        ]);
        $otherEmployee = $employee->replicate();
        $otherEmployee->uuid = (string) Str::uuid();
        $otherEmployee->save();
        $otherRequest = $request->replicate();
        $otherRequest->employeeId = $otherEmployee->id;
        $otherRequest->save();
        $unlinkedRequest = $request->replicate();
        $unlinkedRequest->employeeId = null;
        $unlinkedRequest->save();
        $revisionBefore = $request->revision->data;
        $repository = new EmployeeRepository();
        $this->assertSame($request->id, $repository->latestDraftForEmployee($employee)?->id);
        $repository->updateDetails($employee, ['uuid' => $canonicalParty->uuid] + $partyData, [], []);
        $employee->refresh();
        $this->assertSame($canonicalParty->id, $employee->partyId);
        $this->assertSame($request->id, $repository->latestDraftForEmployee($employee)?->id, 'A party relink must not invalidate the draft of the same employee UUID.');
        $this->assertSame($canonicalParty->id, $request->fresh()->partyId);
        $this->assertSame($oldParty->id, $otherRequest->fresh()->partyId);
        $this->assertSame($oldParty->id, $unlinkedRequest->fresh()->partyId);
        $this->assertSame($revisionBefore, $request->fresh()->revision->data);
    }

    public function test_login_rejects_ambiguous_unlinked_requests_in_the_same_division(): void
    {
        $party = $this->partyData();
        $first = $this->newPositionRequest(101, 'division-a', '2024-01-01 12:00:00', $party);
        $second = $this->newPositionRequest(102, 'division-a', '2024-01-02 12:00:00', $party);
        $remote = ['uuid' => 'employee-a', 'employee_type' => 'SPECIALIST', 'position' => 'P8', 'start_date' => '2024-01-01', 'division_id' => 'division-a', 'party' => $party];
        $match = (new ReflectionMethod(EmployeeCreate::class, 'findMatchingLocalRequest'))->invoke(new EmployeeCreate(), collect([$first, $second]), $remote);
        $this->assertNull($match);
    }

    public function test_missing_remote_division_cannot_match_a_request_with_a_known_division(): void
    {
        $party = $this->partyData();
        $request = $this->newPositionRequest(101, 'division-a', '2024-01-01 12:00:00', $party);
        $remote = ['uuid' => 'employee-a', 'employee_type' => 'SPECIALIST', 'position' => 'P8', 'start_date' => '2024-01-01', 'party' => $party];
        $match = (new ReflectionMethod(EmployeeCreate::class, 'findMatchingLocalRequest'))->invoke(new EmployeeCreate(), collect([$request]), $remote);
        $this->assertNull($match);
    }

    public function test_a_new_owner_without_division_can_still_match_on_first_login(): void
    {
        $party = $this->partyData();
        $request = $this->newPositionRequest(101, 'division-a', '2024-01-01 12:00:00', $party);
        $request->employeeType = 'OWNER';
        $request->setRelation('division', null);
        $remote = ['uuid' => 'owner-a', 'employee_type' => 'OWNER', 'position' => 'P8', 'start_date' => '2024-01-01', 'party' => $party];
        $match = (new ReflectionMethod(EmployeeCreate::class, 'findMatchingLocalRequest'))->invoke(new EmployeeCreate(), collect([$request]), $remote);
        $this->assertSame(101, $match?->id);
    }

    public function test_latest_request_is_preferred_when_versions_are_linked_to_the_exact_same_employee(): void
    {
        $party = $this->partyData();
        $employee = new Employee(['uuid' => 'employee-a', 'position' => 'P8', 'employee_type' => 'SPECIALIST']);
        $employee->forceFill(['id' => 201]);
        $requests = [
            $this->newPositionRequest(101, 'division-a', '2024-01-01 12:00:00', $party),
            $this->newPositionRequest(102, 'division-a', '2024-01-02 12:00:00', $party),
        ];
        foreach ($requests as $request) {
            $request->employeeId = $employee->id;
            $request->setRelation('employee', $employee);
        }
        $remote = ['uuid' => 'employee-a', 'position' => 'P8', 'employee_type' => 'SPECIALIST', 'start_date' => '2024-01-01', 'party' => $party];
        $match = (new ReflectionMethod(EmployeeCreate::class, 'findMatchingLocalRequest'))->invoke(new EmployeeCreate(), collect($requests), $remote);
        $this->assertSame(102, $match?->id);
    }

    public function test_employee_list_validation_preserves_nested_division_and_tax_identity(): void
    {
        $divisionUuid = (string) Str::uuid();
        $response = $this->listResponse([$this->remoteEmployee((string) Str::uuid(), $divisionUuid, ['uuid' => (string) Str::uuid()] + $this->partyData())]);
        $data = $response->validate();
        $this->assertSame($divisionUuid, data_get($data, '0.division.uuid'));
        $this->assertSame('1234567890', data_get($data, '0.party.tax_id'));
    }

    public function test_default_employee_list_preserves_the_bulk_upsert_contract(): void
    {
        $response = $this->listResponse([
            $this->remoteEmployee((string) Str::uuid(), (string) Str::uuid(), ['uuid' => (string) Str::uuid()] + $this->partyData()),
        ], withIdentity: false);
        $row = $response->validate()[0];
        $this->assertArrayNotHasKey('division', $row);
        $this->assertArrayNotHasKey('division_id', $row);
        $this->assertArrayNotHasKey('tax_id', $row['party']);
    }

    public function test_shared_matcher_uses_the_normalized_nested_division(): void
    {
        $request = $this->newPositionRequest(101, 'division-a', '2024-01-01 12:00:00', $this->partyData());
        $remote = ['uuid' => 'employee-a', 'position' => 'P8', 'employee_type' => 'SPECIALIST', 'start_date' => '2024-01-01', 'division' => ['uuid' => 'division-a']];
        $this->assertSame('employee-a', (new EmployeeRequestMatcher())->pickFromApprovedList($request, [$remote])['uuid'] ?? null);
    }

    public static function responseOrders(): array
    {
        return ['A then B' => [false], 'B then A' => [true]];
    }

    #[DataProvider('responseOrders')]
    public function test_full_login_keeps_new_position_certificates_in_their_own_divisions(bool $reverse): void
    {
        [$legalEntity, $party, $user] = $this->fixture();
        $requests = [];
        $remote = [];
        $expectedEmployeeUuids = [];
        foreach (['THERAPIST', 'SURGERY'] as $index => $code) {
            $division = Division::create(['uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id, 'name' => $code, 'email' => 'division@example.test']);
            $requests[] = $this->signedPositionRequest($legalEntity, $party, $user, $division, $code, $index);
            $remote[] = $this->remoteEmployee((string) Str::uuid(), $division->uuid, $party->toArray());
            $expectedEmployeeUuids[] = $remote[$index]['id'];
        }
        if ($reverse) {
            $remote = array_reverse($remote);
        }
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()
            ->withArgs(fn (array $filters, int $page, bool $withIdentity): bool => $page === 1 && $withIdentity)
            ->andReturn($this->listResponse($remote));
        $this->instance(EmployeeApi::class, $api);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'isolated-token');

        (new EmployeeCreate())->handle(new EHealthUserLogin($user, $legalEntity, (string) Str::uuid(), []));

        foreach ($requests as $index => $request) {
            $employee = $request->fresh()->employee;
            $this->assertNotNull($employee);
            $this->assertSame($expectedEmployeeUuids[$index], $employee->uuid);
            $this->assertSame($request->divisionId, $employee->divisionId);
            $this->assertSame([$index === 0 ? 'THERAPIST' : 'SURGERY'], $employee->specialities()->pluck('speciality')->all());
        }
        $this->assertNotSame($requests[0]->fresh()->employeeId, $requests[1]->fresh()->employeeId);
    }

    public static function partyUuidVariants(): array
    {
        return ['local revision' => [false], 'legacy revision with party UUID' => [true]];
    }

    #[DataProvider('partyUuidVariants')]
    public function test_an_approved_own_request_can_still_be_applied_after_party_relink(bool $hasPartyUuid): void
    {
        [$legalEntity, $party, $user] = $this->fixture();
        $division = Division::create(['uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id, 'name' => 'Clinic', 'email' => 'division@example.test']);
        $request = $this->signedPositionRequest($legalEntity, $party, $user, $division, 'THERAPIST', 0);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id, 'party_id' => $party->id,
            'position' => 'P8', 'employee_type' => 'SPECIALIST', 'start_date' => '2024-01-01', 'status' => Status::APPROVED,
        ]);
        $request->update(['employee_id' => $employee->id]);
        $data = $request->revision->data;
        $data['employee_uuid'] = $employee->uuid;
        if ($hasPartyUuid) {
            $data['party']['uuid'] = $party->uuid;
        }
        $request->revision->update(['data' => $data]);
        $canonical = Party::create(['uuid' => (string) Str::uuid()] + $this->partyData());
        (new EmployeeRepository())->updateDetails($employee, ['uuid' => $canonical->uuid] + $this->partyData(), [], []);
        app(EmployeeRequestProcessor::class)->applyApprovedRequest($request->fresh(), ['employee_id' => $employee->uuid, 'status' => 'APPROVED']);
        $this->assertSame($canonical->id, $employee->fresh()->partyId);
        $this->assertSame($canonical->id, $request->fresh()->partyId);
        $this->assertSame(['THERAPIST'], $employee->specialities()->pluck('speciality')->all());
        $this->assertSame($data, $request->fresh()->revision->data);
    }

    private function fixture(): array
    {
        $type = LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT']);
        $legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id, 'is_active' => true]);
        $this->instance('legalEntity', $legalEntity);
        $party = Party::create(['uuid' => (string) Str::uuid()] + $this->partyData());
        $user = User::create(['uuid' => (string) Str::uuid(), 'party_id' => $party->id, 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);

        return [$legalEntity, $party, $user];
    }

    private function partyData(): array
    {
        return ['tax_id' => '1234567890', 'first_name' => 'Тест', 'last_name' => 'Посада', 'second_name' => null,
            'birth_date' => '1990-01-01', 'gender' => 'MALE', 'no_tax_id' => false];
    }

    private function signedPositionRequest(LegalEntity $legalEntity, Party $party, User $user, Division $division, string $code, int $order): EmployeeRequest
    {
        $request = EmployeeRequest::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id, 'party_id' => $party->id,
            'position' => 'P8', 'employee_type' => 'SPECIALIST', 'start_date' => '2024-01-01',
            'email' => $user->email, 'division_id' => $division->id, 'division_uuid' => $division->uuid,
            'status' => RequestStatus::NEW, 'created_at' => '2024-01-0'.($order + 1).' 12:00:00',
        ]);
        $request->revision()->create([
            'status' => RevisionStatus::SENT,
            'data' => [
                'party' => $this->partyData(), 'documents' => [], 'phones' => [],
                'employee_request_data' => ['position' => 'P8', 'employee_type' => 'SPECIALIST', 'start_date' => '2024-01-01', 'division_id' => $division->id],
                'doctor' => ['specialities' => [[
                    'speciality' => $code, 'speciality_officio' => true, 'level' => 'SECOND', 'qualification_type' => 'AWARDING',
                    'attestation_name' => 'МОЗ', 'attestation_date' => '2024-01-01', 'certificate_number' => 'CERT-'.$code,
                ]]],
            ],
        ]);

        return $request;
    }

    private function remoteEmployee(string $uuid, string $divisionUuid, array $party): array
    {
        return ['id' => $uuid, 'employee_type' => 'SPECIALIST', 'position' => 'P8', 'start_date' => '2024-01-01',
            'status' => 'APPROVED', 'is_active' => true, 'division' => ['id' => $divisionUuid],
            'party' => ['id' => $party['uuid']] + $this->partyData()];
    }

    private function listResponse(array $rows, bool $withIdentity = true): EHealthResponse
    {
        $raw = Mockery::mock(EHealthResponse::class);
        $raw->shouldReceive('getData')->once()->andReturn($rows);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturnUsing(
            fn (): array => (new ReflectionMethod(EmployeeApi::class, 'validateMany'))->invoke(new EmployeeApi(), $raw, $withIdentity)
        );

        return $response;
    }

    private function newPositionRequest(int $id, string $divisionUuid, string $createdAt, array $party): EmployeeRequest
    {
        $request = new EmployeeRequest([
            'employee_type' => 'SPECIALIST', 'position' => 'P8', 'start_date' => '2024-01-01',
            'status' => RequestStatus::NEW, 'created_at' => $createdAt,
        ]);
        $request->forceFill(['id' => $id]);
        $request->setRelation('division', new Division(['uuid' => $divisionUuid]));
        $request->setRelation('revision', new Revision(['data' => [
            'party' => $party, 'doctor' => ['specialities' => [['speciality' => $divisionUuid === 'division-a' ? 'THERAPIST' : 'SURGERY']]],
        ]]));

        return $request;
    }
}
