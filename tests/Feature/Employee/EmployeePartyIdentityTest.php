<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Events\EHealthUserLogin;
use App\Listeners\eHealth\EmployeeCreate;
use App\Livewire\LegalEntity\EditLegalEntity;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use ReflectionProperty;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\Revision;
use App\Models\User;
use App\Repositories\EmployeeRepository;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeePartyIdentityTest extends TestCase
{
    use DatabaseTransactions;

    public static function modelTypes(): array
    {
        return [[Employee::class], [EmployeeRequest::class]];
    }

    private function party(?string $uuid = null): Party
    {
        return Party::create(['uuid' => $uuid, 'first_name' => 'Test', 'last_name' => 'Person']);
    }

    private function model(string $class, ?Party $party = null): Employee|EmployeeRequest
    {
        return $class::create([
            'uuid' => (string) Str::uuid(),
            'position' => 'P1',
            'employee_type' => 'HR',
            'start_date' => '2026-01-01',
            'party_id' => $party?->id,
        ]);
    }

    #[DataProvider('modelTypes')]
    public function test_existing_uuid_relinks_only_target_and_syncs_canonical_relations(string $class): void
    {
        $old = $this->party((string) Str::uuid());
        $canonical = $this->party((string) Str::uuid());
        $target = $this->model($class, $old);
        $other = $this->model($class, $old);
        $old->phones()->create(['type' => 'MOBILE', 'number' => '+380501111111']);
        $old->documents()->create(['type' => 'PASSPORT', 'number' => 'OLD']);
        $target->load('party');
        $count = Party::count();

        $repository = new EmployeeRepository();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $repository->updateDetails($target, [
                'uuid' => $canonical->uuid,
                'first_name' => 'Updated',
                'email' => 'ignored@example.test',
            ], [['type' => 'PASSPORT', 'number' => 'NEW']], [['type' => 'MOBILE', 'number' => '+380502222222']]);
        }

        $this->assertSame($canonical->id, $target->fresh()->partyId);
        $this->assertSame($canonical->id, $target->party->id);
        $this->assertSame('Updated', $target->party->firstName);
        $this->assertSame($old->id, $other->fresh()->partyId);
        $this->assertSame($old->uuid, $old->fresh()->uuid);
        $this->assertSame('Test', $old->fresh()->firstName);
        $this->assertSame('OLD', $old->documents()->sole()->number);
        $this->assertSame('+380501111111', $old->phones()->sole()->number);
        $this->assertSame('NEW', $canonical->documents()->sole()->number);
        $this->assertSame('+380502222222', $canonical->phones()->sole()->number);
        $this->assertSame($count, Party::count());
    }

    #[DataProvider('modelTypes')]
    public function test_first_uuid_preserves_links_to_local_draft(string $class): void
    {
        $draft = $this->party();
        $target = $this->model($class, $draft);
        $other = $this->model($class, $draft);
        $uuid = (string) Str::uuid();
        (new EmployeeRepository())->updateDetails($target, ['uuid' => $uuid], [], []);
        $this->assertSame($draft->id, $target->partyId);
        $this->assertSame($draft->id, $other->fresh()->partyId);
        $this->assertSame($uuid, $draft->fresh()->uuid);
    }

    #[DataProvider('modelTypes')]
    public function test_unlinked_model_reuses_existing_uuid(string $class): void
    {
        $canonical = $this->party((string) Str::uuid());
        $target = $this->model($class);
        (new EmployeeRepository())->updateDetails($target, ['uuid' => $canonical->uuid], [], []);
        $this->assertSame($canonical->id, $target->partyId);
    }

    #[DataProvider('modelTypes')]
    public function test_new_uuid_does_not_rewrite_identity_shared_by_other_records(string $class): void
    {
        $old = $this->party((string) Str::uuid());
        $target = $this->model($class, $old);
        $uuid = (string) Str::uuid();
        (new EmployeeRepository())->updateDetails($target, [
            'uuid' => $uuid, 'first_name' => 'Remote', 'last_name' => 'Person',
        ], [], []);
        $this->assertNotSame($old->id, $target->partyId);
        $this->assertSame($uuid, $target->party->uuid);
        $this->assertSame($old->uuid, $old->fresh()->uuid);
    }

    #[DataProvider('modelTypes')]
    public function test_missing_uuid_does_not_adopt_an_unrelated_local_party(string $class): void
    {
        $unrelated = $this->party();
        $target = $this->model($class);
        (new EmployeeRepository())->updateDetails($target, ['first_name' => 'New', 'last_name' => 'Person'], [], []);
        $this->assertNotSame($unrelated->id, $target->partyId);
        $this->assertSame('Test', $unrelated->fresh()->firstName);
        $this->assertNull($target->party->uuid);
    }

    #[DataProvider('modelTypes')]
    public function test_null_and_empty_uuid_preserve_known_identity(string $class): void
    {
        $this->party();
        $party = $this->party((string) Str::uuid());
        $target = $this->model($class, $party);
        foreach ([null, ''] as $uuid) {
            (new EmployeeRepository())->updateDetails($target, ['uuid' => $uuid, 'first_name' => 'Updated'], [], []);
            $this->assertSame($party->id, $target->fresh()->partyId);
            $this->assertSame($party->uuid, $party->fresh()->uuid);
        }
    }

    #[DataProvider('modelTypes')]
    public function test_stale_relation_uses_persisted_foreign_key_without_losing_dirty_attributes(string $class): void
    {
        $old = $this->party();
        $current = $this->party();
        $target = $this->model($class, $old)->load('party');
        $class::whereKey($target->id)->update(['party_id' => $current->id]);
        $target->position = 'P2';
        (new EmployeeRepository())->updateDetails($target, ['first_name' => 'Updated'], [], []);
        $this->assertSame($current->id, $target->partyId);
        $this->assertSame('Updated', $current->fresh()->firstName);
        $this->assertSame('Test', $old->fresh()->firstName);
        $this->assertSame('P2', $target->fresh()->position);
    }

    #[DataProvider('modelTypes')]
    public function test_relation_failure_rolls_back_party_data_and_association(string $class): void
    {
        $old = $this->party((string) Str::uuid());
        $canonical = $this->party((string) Str::uuid());
        $target = $this->model($class, $old);
        try {
            (new EmployeeRepository())->updateDetails($target, [
                'uuid' => $canonical->uuid, 'first_name' => 'Updated',
            ], [['type' => 'PASSPORT', 'number' => null]], []);
            $this->fail('Expected the document NOT NULL constraint to fail.');
        } catch (QueryException) {
            $this->assertSame($old->id, $target->fresh()->partyId);
            $this->assertSame('Test', $canonical->fresh()->firstName);
        }
    }

    private function legalEntity(): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->insertGetId(['name' => 'TEST_' . Str::random(8)]);

        return LegalEntity::create(['uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $typeId]);
    }

    private function request(LegalEntity $legalEntity, Party $party, string $email): EmployeeRequest
    {
        $request = $this->model(EmployeeRequest::class, $party);
        $request->update(['legal_entity_id' => $legalEntity->id, 'email' => $email, 'status' => RequestStatus::NEW]);
        $request->revision()->save(new Revision([
            'status' => RevisionStatus::PENDING,
            'data' => [
                'party' => ['uuid' => $party->uuid, 'first_name' => 'Test', 'last_name' => 'Person', 'second_name' => null, 'tax_id' => '1111111111'],
                'employee_request_data' => ['position' => 'P1', 'employee_type' => 'HR', 'start_date' => '2026-01-01'],
            ],
        ]));

        return $request;
    }

    private function event(User $user, LegalEntity $legalEntity): EHealthUserLogin
    {
        $tokens = Mockery::mock(TokenStorage::class);
        $tokens->shouldReceive('getBearerToken')->andReturn('test-token');
        $this->instance(TokenStorage::class, $tokens);

        return new EHealthUserLogin($user, $legalEntity, (string) Str::uuid(), []);
    }

    public function test_login_ignores_other_legal_entities_with_same_email(): void
    {
        $user = User::create(['email' => 'employee878@example.test', 'password' => 'test']);
        $this->request($this->legalEntity(), $this->party(), $user->email);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldNotReceive('getMany');
        $this->instance(EmployeeApi::class, $api);
        (new EmployeeCreate())->handle($this->event($user, $this->legalEntity()));
        $this->assertNull($user->fresh()->partyId);
    }

    public function test_login_without_remote_match_does_not_associate_user(): void
    {
        $user = User::create(['email' => 'employee878@example.test', 'password' => 'test']);
        $legalEntity = $this->legalEntity();
        $this->request($legalEntity, $this->party(), $user->email);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([]);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->andReturn($response);
        $this->instance(EmployeeApi::class, $api);
        (new EmployeeCreate())->handle($this->event($user, $legalEntity));
        $this->assertNull($user->fresh()->partyId);
    }

    public static function linkedRequests(): array
    {
        return ['new employee request' => [false], 'existing employee request' => [true]];
    }

    #[DataProvider('linkedRequests')]
    public function test_login_links_employee_request_and_new_user_to_remote_party(bool $existingEmployee): void
    {
        $user = User::create(['email' => 'employee878@example.test', 'password' => 'test']);
        $legalEntity = $this->legalEntity();
        $old = $this->party((string) Str::uuid());
        $canonical = $this->party((string) Str::uuid());
        $request = $this->request($legalEntity, $old, $user->email);
        $employee = $this->model(Employee::class, $old);
        if ($existingEmployee) {
            $employee->update(['legal_entity_id' => $legalEntity->id, 'status' => 'APPROVED']);
            $request->update(['employee_id' => $employee->id, 'status' => RequestStatus::APPROVED]);
        }
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([[
            'uuid' => $employee->uuid, 'status' => 'APPROVED', 'position' => 'P1',
            'employee_type' => 'HR', 'start_date' => '2026-01-01',
            'party' => ['uuid' => $canonical->uuid, 'tax_id' => '1111111111', 'first_name' => 'Test', 'last_name' => 'Person', 'second_name' => null],
        ]]);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->andReturn($response);
        $this->instance(EmployeeApi::class, $api);
        (new EmployeeCreate())->handle($this->event($user, $legalEntity));
        $this->assertSame($canonical->id, $employee->fresh()->partyId);
        $this->assertSame($canonical->id, $request->fresh()->partyId);
        $this->assertSame($canonical->id, $user->fresh()->partyId);
        $this->assertSame(RequestStatus::APPROVED, $request->fresh()->status);
    }

    /** Create the request through the actual Legal Entity owner-edit persistence path. */
    private function ownerEdit(bool $changeEmail = true): array
    {
        $legalEntity = $this->legalEntity();
        $party = $this->party((string) Str::uuid());
        $originalUser = User::create([
            'email' => 'original-owner@example.test', 'password' => 'test', 'party_id' => $party->id,
        ]);
        $employee = $this->model(Employee::class, $party);
        $employee->update([
            'employee_type' => 'OWNER', 'status' => 'APPROVED', 'is_active' => true,
            'legal_entity_id' => $legalEntity->id, 'legal_entity_uuid' => $legalEntity->uuid,
            'user_id' => $originalUser->id,
        ]);
        $user = $changeEmail
            ? User::create(['email' => 'new-owner-email@example.test', 'password' => 'test'])
            : $originalUser;
        Auth::shouldUse('ehealth');
        Auth::guard('ehealth')->setUser($originalUser);
        $this->instance('legalEntity', $legalEntity);
        $component = new EditLegalEntity();
        (new ReflectionProperty($component, 'legalEntity'))->setValue($component, $legalEntity);
        $uuid = (string) Str::uuid();
        (new ReflectionMethod($component, 'createEmployeeRequest'))->invoke($component, $legalEntity, [
            'employee_id' => $employee->id,
            'owner' => [
                'employee_id' => $employee->uuid, 'party_id' => $party->id,
                'position' => 'P1', 'email' => $user->email, 'first_name' => 'Test',
                'last_name' => 'Updated', 'gender' => 'MALE', 'birth_date' => '1990-01-01',
                'tax_id' => '1111111111', 'no_tax_id' => false, 'documents' => [], 'phones' => [],
            ],
        ], $uuid);
        $request = EmployeeRequest::where('uuid', $uuid)->firstOrFail();
        $this->assertNull($request->startDate);
        $this->assertSame($employee->id, $request->employeeId);
        $this->assertSame($party->id, $request->partyId);
        $this->assertSame(RequestStatus::NEW, $request->status);
        $this->assertSame(RevisionStatus::SENT, $request->revision->status);

        return [$user, $legalEntity, $employee, $request, $party];
    }

    public static function ownerEmailChanges(): array
    {
        return ['new email' => [true], 'same email' => [false]];
    }

    #[DataProvider('ownerEmailChanges')]
    public function test_owner_edit_retains_party_link_even_without_remote_match(bool $changeEmail): void
    {
        [$user, $legalEntity, $employee, $request, $party] = $this->ownerEdit($changeEmail);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([]);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->andReturn($response);
        $this->instance(EmployeeApi::class, $api);

        (new EmployeeCreate())->handle($this->event($user, $legalEntity));

        $this->assertSame($party->id, $user->fresh()->partyId);
        $this->assertSame('Person', $employee->fresh()->party->lastName);
        $this->assertSame(RequestStatus::NEW, $request->fresh()->status);
        $this->assertSame(RevisionStatus::SENT, $request->revision->fresh()->status);
    }

    public function test_owner_email_edit_restores_party_when_remote_owner_already_exists(): void
    {
        [$user, $legalEntity, $employee, $request, $party] = $this->ownerEdit();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([[
            'uuid' => $employee->uuid, 'status' => 'APPROVED', 'position' => 'P1',
            'employee_type' => 'OWNER', 'start_date' => '2026-01-01',
            'party' => ['uuid' => $party->uuid, 'tax_id' => '1111111111', 'first_name' => 'Test', 'last_name' => 'Person', 'second_name' => null],
        ]]);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->once()->andReturn($response);
        $this->instance(EmployeeApi::class, $api);

        (new EmployeeCreate())->handle($this->event($user, $legalEntity));

        $this->assertSame($party->id, $user->fresh()->partyId);
        $this->assertSame('Person', $employee->fresh()->party->lastName);
        $this->assertSame($employee->id, $request->fresh()->employeeId);
        $this->assertSame($party->id, $request->fresh()->partyId);
    }

    public static function invalidEditLinks(): array
    {
        return [['different party'], ['different legal entity'], ['ambiguous parties'], ['existing user party']];
    }

    #[DataProvider('invalidEditLinks')]
    public function test_early_link_does_not_reassign_unverified_or_existing_identity(string $case): void
    {
        [$user, $legalEntity, $employee, $request, $party] = $this->ownerEdit();
        if ($case === 'different party') {
            $request->update(['party_id' => $this->party()->id]);
        } elseif ($case === 'different legal entity') {
            $employee->update(['legal_entity_id' => $this->legalEntity()->id]);
        } elseif ($case === 'ambiguous parties') {
            $otherParty = $this->party();
            $otherEmployee = $this->model(Employee::class, $otherParty);
            $otherEmployee->update(['legal_entity_id' => $legalEntity->id, 'status' => 'APPROVED']);
            $otherRequest = $this->request($legalEntity, $otherParty, $user->email);
            $otherRequest->update(['employee_id' => $otherEmployee->id]);
        } else {
            $user->update(['party_id' => $this->party()->id]);
        }
        $originalPartyId = $user->partyId;
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([]);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('getMany')->andReturn($response);
        $this->instance(EmployeeApi::class, $api);

        (new EmployeeCreate())->handle($this->event($user, $legalEntity));

        $this->assertSame($originalPartyId, $user->fresh()->partyId);
    }

    public function test_processor_copies_resolved_party_to_existing_request(): void
    {
        $old = $this->party((string) Str::uuid());
        $canonical = $this->party((string) Str::uuid());
        $request = $this->request($this->legalEntity(), $old, 'employee878@example.test');
        $employee = $this->model(Employee::class, $old);
        $request->update(['employee_id' => $employee->id]);
        app(EmployeeRequestProcessor::class)->applyApprovedRequest($request, [
            'employee_id' => $employee->uuid, 'status' => 'APPROVED', 'party' => ['uuid' => $canonical->uuid],
        ]);
        $this->assertSame($canonical->id, $employee->fresh()->partyId);
        $this->assertSame($canonical->id, $request->fresh()->partyId);
        $this->assertSame(RequestStatus::APPROVED, $request->fresh()->status);
        $this->assertSame(RevisionStatus::APPLIED, $request->revision->fresh()->status);
    }
}
