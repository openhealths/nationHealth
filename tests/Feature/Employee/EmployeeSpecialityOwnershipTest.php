<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Enums\Status;
use App\Jobs\EmployeeDetailsUpsert;
use App\Listeners\eHealth\EmployeeCreate as EmployeeCreateListener;
use App\Livewire\Employee\EmployeeEdit;
use App\Livewire\Employee\EmployeePositionAdd;
use App\Livewire\Employee\EmployeeRequestEdit;
use App\Livewire\Employee\EmployeeShow;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\EmployeeRepository;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeSpecialityOwnershipTest extends TestCase
{
    use DatabaseTransactions;
    use FakesEmployeeDictionaries;

    private LegalEntity $legalEntity;
    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmployeeDictionaries();
        Cache::put('knedp_certificate_authority', [], 60);
        $type = LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT']);
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);
        $this->party = Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Тест', 'last_name' => 'Ізоляція',
            'birth_date' => '1990-01-01', 'gender' => 'MALE', 'tax_id' => '1234567890', 'no_tax_id' => false,
        ]);
        $this->actingAs(User::create(['email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]));
        Gate::before(fn (): bool => true);
    }

    public static function medicalPositions(): array
    {
        return [
            'specialist' => ['OUTPATIENT', 'SPECIALIST', 'THERAPIST'],
            'assistant' => ['OUTPATIENT', 'ASSISTANT', 'NURSING'],
            'doctor' => ['PRIMARY_CARE', 'DOCTOR', 'FAMILY_DOCTOR'],
        ];
    }

    #[DataProvider('medicalPositions')]
    public function test_show_and_edit_only_load_the_current_positions_certificates(string $legalEntityType, string $employeeType, string $speciality): void
    {
        $this->legalEntity->type()->associate(LegalEntityType::firstOrCreate(['name' => $legalEntityType]));
        $this->legalEntity->save();
        $employee = $this->employee($employeeType, [$speciality]);
        $other = $this->employee('SPECIALIST', ['SURGERY']);
        $otherRequest = $this->request($other, ['NURSING']);
        $otherRequest->specialities()->create($this->certificate('PEDIATRICIAN'));

        foreach ([EmployeeShow::class, EmployeeEdit::class] as $component) {
            Livewire::test($component, ['legalEntity' => $this->legalEntity, 'employee' => $employee->fresh()])
                ->assertOk()
                ->assertSet('form.doctor.specialities', fn (array $rows): bool => array_column($rows, 'speciality') === [$speciality]);
        }
        $this->assertSame(['SURGERY'], $other->specialities()->pluck('speciality')->all());
    }

    public function test_adding_a_position_starts_with_no_specialities_from_the_party(): void
    {
        $this->request($this->employee('SPECIALIST', ['THERAPIST']), ['NURSING']);
        Livewire::test(EmployeePositionAdd::class, ['legalEntity' => $this->legalEntity, 'party' => $this->party])
            ->assertOk()->assertSet('form.doctor.specialities', []);
    }

    public function test_edit_does_not_load_another_positions_draft_even_if_it_was_wrongly_linked(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($this->employee('ASSISTANT', ['NURSING']), ['NURSING']);
        $request->update(['employee_id' => $employee->id]);
        Livewire::test(EmployeeEdit::class, ['legalEntity' => $this->legalEntity, 'employee' => $employee])
            ->assertOk()->assertSet('employeeRequestId', null)->assertSet('form.employeeType', 'SPECIALIST')
            ->assertSet('form.doctor.specialities', fn (array $rows): bool => array_column($rows, 'speciality') === ['THERAPIST']);
    }

    public function test_a_request_does_not_inherit_certificates_from_a_wrongly_linked_employee(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST', 'SURGERY']);
        $request = $this->request($this->employee('ASSISTANT', ['NURSING']), ['NURSING']);
        $request->update(['employee_id' => $employee->id]);
        Livewire::test(EmployeeRequestEdit::class, ['legalEntity' => $this->legalEntity, 'employee_request' => $request])
            ->assertOk()->assertSet('form.employeeType', 'ASSISTANT')
            ->assertSet('form.doctor.specialities', fn (array $rows): bool => array_column($rows, 'speciality') === ['NURSING']);
    }

    public static function replacementLists(): array
    {
        return ['shorter list' => [['THERAPIST']], 'empty list' => [[]]];
    }

    #[DataProvider('replacementLists')]
    public function test_draft_specialities_replace_the_list_instead_of_leaving_original_tail_rows(array $codes): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST', 'SURGERY']);
        $request = $this->request($employee, $codes);
        Livewire::test(EmployeeEdit::class, ['legalEntity' => $this->legalEntity, 'employee' => $employee])
            ->assertOk()->assertSet('employeeRequestId', $request->id)
            ->assertSet('form.doctor.specialities', fn (array $rows): bool => array_column($rows, 'speciality') === $codes);
    }

    public function test_detail_sync_does_not_relink_a_request_from_another_employee_of_the_same_party(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $other = $this->employee('SPECIALIST', ['SURGERY']);
        $request = $this->request($other, ['SURGERY']);
        $this->syncDetails($employee);
        $this->assertSame($other->id, $request->fresh()->employeeId);
        $this->assertSame(['SURGERY'], $other->specialities()->pluck('speciality')->all());
        $this->assertSame(['THERAPIST'], $employee->specialities()->pluck('speciality')->all());
    }

    public function test_detail_sync_does_not_relink_a_request_from_another_party(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($this->employee('SPECIALIST', ['SURGERY']), ['SURGERY']);
        $otherParty = Party::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Інший', 'last_name' => 'Працівник']);
        $request->update(['employee_id' => null, 'party_id' => $otherParty->id]);
        $this->syncDetails($employee);
        $this->assertNull($request->fresh()->employeeId);
    }

    public function test_detail_sync_can_link_the_current_positions_unlinked_request(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($employee, ['THERAPIST']);
        $request->update(['employee_id' => null, 'uuid' => (string) Str::uuid()]);
        $this->syncDetails($employee);
        $this->assertSame($employee->id, $request->fresh()->employeeId);
    }

    public function test_detail_sync_does_not_link_an_unsigned_new_position_draft(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $employee->update(['inserted_at' => '2024-01-01 12:00:00']);
        $request = $this->request($employee, ['SURGERY']);
        $request->update(['employee_id' => null]);
        $this->syncDetails($employee);
        $this->assertNull($request->fresh()->employeeId);
        $this->assertSame('2024-01-01 12:00:00', $employee->fresh()->getRawOriginal('inserted_at'));
    }

    public function test_detail_sync_can_match_a_first_login_request_by_verified_revision_party_identity(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($employee, ['THERAPIST']);
        $request->update(['employee_id' => null, 'party_id' => null, 'uuid' => (string) Str::uuid()]);
        $data = $request->revision->data;
        unset($data['employee_uuid']);
        $request->revision->update(['data' => $data]);
        $this->syncDetails($employee);
        $this->assertSame($employee->id, $request->fresh()->employeeId);
    }

    public function test_detail_sync_does_not_guess_between_unlinked_requests_for_two_identical_positions(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $first = $this->request($employee, ['THERAPIST']);
        $second = $this->request($employee, ['SURGERY']);
        foreach ([$first, $second] as $request) {
            $request->update(['employee_id' => null, 'uuid' => (string) Str::uuid()]);
        }
        $this->syncDetails($employee);
        $this->assertNull($first->fresh()->employeeId);
        $this->assertNull($second->fresh()->employeeId);
    }

    public function test_a_wrongly_linked_request_cannot_be_saved_or_used_as_a_legacy_certificate_source(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($this->employee('ASSISTANT', ['NURSING']), ['NURSING']);
        $request->update(['employee_id' => $employee->id]);
        $this->assertSame([], (new EmployeeRepository())->originalSpecialities($request, $this->legalEntity->id, 'SPECIALIST'));
        $editor = Livewire::test(EmployeeRequestEdit::class, ['legalEntity' => $this->legalEntity, 'employee_request' => $request])->instance();
        $this->expectException(ValidationException::class);
        (new ReflectionMethod($editor, 'handleDraftPersistence'))->invoke($editor);
    }

    public function test_approved_new_position_specialities_are_applied_only_to_that_employee(): void
    {
        $existing = $this->employee('SPECIALIST', ['THERAPIST']);
        $assistant = new Employee([
            'legal_entity_id' => $this->legalEntity->id, 'party_id' => $this->party->id,
            'employee_type' => 'ASSISTANT', 'position' => 'P3',
        ]);
        $request = $this->request($assistant, ['NURSING']);
        $request->update(['uuid' => (string) Str::uuid()]);
        $newUuid = (string) Str::uuid();
        app(EmployeeRequestProcessor::class)->applyApprovedRequest($request, ['employee_id' => $newUuid, 'status' => 'APPROVED']);
        $assistant = Employee::whereUuid($newUuid)->sole();
        $this->assertNotSame($existing->id, $assistant->id);
        $this->assertSame($assistant->id, $request->fresh()->employeeId);
        $this->assertSame(['NURSING'], $assistant->specialities()->pluck('speciality')->all());
        $this->assertSame(['THERAPIST'], $existing->specialities()->pluck('speciality')->all());
    }

    public function test_edit_ignores_a_foreign_revision_even_when_the_request_columns_match(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($employee, ['NURSING']);
        $data = $request->revision->data;
        $data['employee_request_data']['employee_type'] = 'ASSISTANT';
        $request->revision->update(['data' => $data]);
        Livewire::test(EmployeeEdit::class, ['legalEntity' => $this->legalEntity, 'employee' => $employee])
            ->assertOk()->assertSet('employeeRequestId', null)
            ->assertSet('form.doctor.specialities', fn (array $rows): bool => array_column($rows, 'speciality') === ['THERAPIST']);
    }

    public function test_approved_request_cannot_overwrite_a_different_employee_of_the_same_party(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $request = $this->request($this->employee('ASSISTANT', ['NURSING']), ['NURSING']);
        try {
            app(EmployeeRequestProcessor::class)->applyApprovedRequest($request, ['employee_id' => $employee->uuid, 'status' => 'APPROVED']);
            $this->fail('A request for another position must not overwrite this employee.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot apply employee request to a different employee or position.', $exception->getMessage());
        }
        $this->assertSame('SPECIALIST', $employee->fresh()->employeeType);
        $this->assertSame(['THERAPIST'], $employee->specialities()->pluck('speciality')->all());
    }

    public function test_login_matching_does_not_reuse_a_request_linked_to_another_employee_with_identical_fields(): void
    {
        $employee = $this->employee('SPECIALIST', ['THERAPIST']);
        $other = $this->employee('SPECIALIST', ['SURGERY']);
        $request = $this->request($other, ['SURGERY']);
        $remote = [
            'uuid' => $employee->uuid, 'position' => $employee->position, 'employee_type' => $employee->employeeType,
            'start_date' => '2024-01-01', 'party' => ['uuid' => $this->party->uuid, 'tax_id' => $this->party->taxId,
                'first_name' => $this->party->firstName, 'last_name' => $this->party->lastName, 'second_name' => null],
        ];
        $listener = new EmployeeCreateListener();
        $method = new ReflectionMethod($listener, 'findMatchingLocalRequest');
        $this->assertNull($method->invoke($listener, collect([$request]), $remote));
        $remote['uuid'] = $other->uuid;
        $this->assertSame($request->id, $method->invoke($listener, collect([$request]), $remote)?->id);
    }

    private function employee(string $employeeType, array $codes): Employee
    {
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $this->legalEntity->id, 'legal_entity_uuid' => $this->legalEntity->uuid,
            'party_id' => $this->party->id, 'employee_type' => $employeeType, 'position' => $employeeType === 'ASSISTANT' ? 'P3' : 'P8',
            'status' => Status::APPROVED, 'start_date' => '2024-01-01', 'is_active' => true,
        ]);
        foreach ($codes as $index => $code) {
            $employee->specialities()->create($this->certificate($code, $index === 0));
        }

        return $employee;
    }

    private function request(Employee $employee, array $codes): EmployeeRequest
    {
        $request = EmployeeRequest::create([
            'legal_entity_id' => $employee->legalEntityId, 'party_id' => $employee->partyId, 'employee_id' => $employee->id,
            'employee_type' => $employee->employeeType, 'position' => $employee->position, 'start_date' => '2024-01-01',
            'status' => RequestStatus::NEW,
        ]);
        $request->revision()->create([
            'status' => RevisionStatus::PENDING,
            'data' => [
                'employee_uuid' => $employee->uuid,
                'party' => ['uuid' => $this->party->uuid, 'tax_id' => $this->party->taxId, 'first_name' => $this->party->firstName,
                    'last_name' => $this->party->lastName, 'second_name' => null],
                'employee_request_data' => ['employee_type' => $employee->employeeType, 'position' => $employee->position, 'start_date' => '2024-01-01'],
                'doctor' => ['specialities' => array_map(fn (string $code): array => $this->certificate($code), $codes)],
            ],
        ]);

        return $request;
    }

    private function certificate(string $code, bool $primary = true): array
    {
        return [
            'speciality' => $code, 'speciality_officio' => $primary, 'level' => 'SECOND', 'qualification_type' => 'AWARDING',
            'attestation_name' => 'МОЗ', 'attestation_date' => '2024-01-01', 'certificate_number' => 'CERT-'.$code,
        ];
    }

    private function syncDetails(Employee $employee): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'employee' => ['start_date' => '2024-01-01'], 'division' => null,
            'party' => ['uuid' => $this->party->uuid], 'documents' => [], 'phones' => [],
            'specialities' => [$this->certificate('THERAPIST')],
        ]);
        $job = new EmployeeDetailsUpsert($employee, $this->legalEntity);
        (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
    }
}
