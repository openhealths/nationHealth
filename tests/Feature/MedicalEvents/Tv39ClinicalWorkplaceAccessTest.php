<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use App\Enums\User\Role;
use App\Livewire\Person\Records\PatientPrescriptionRequestView;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Policies\CarePlanPolicy;
use App\Policies\CompositionPolicy;
use App\Policies\DeviceRequestRequestPolicy;
use App\Policies\MedicationRequestRequestPolicy;
use App\Policies\ServiceRequestRequestPolicy;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

/** Local U01 acceptance: scopes never substitute for an eligible active employee. */
class Tv39ClinicalWorkplaceAccessTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    private const array SCOPES = [
        'care_plan:read', 'care_plan:write', 'device_request:read', 'device_request:write',
        'medication_request_request:read', 'medication_request_request:sign',
        'composition:create', 'composition:read', 'composition:search',
        'service_request:read', 'service_request:write', 'service_request:use',
    ];

    public static function workplaces(): array
    {
        $cases = [];
        foreach ([LegalEntity::TYPE_PRIMARY_CARE, LegalEntity::TYPE_OUTPATIENT] as $entityType) {
            foreach ([Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::MED_COORDINATOR, Role::MED_ADMIN] as $role) {
                $cases[$entityType.' '.$role->value] = [$entityType, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('workplaces')]
    public function test_all_five_roles_use_the_actual_workplace_and_employee_pair(string $entityType, Role $role): void
    {
        $context = $this->compositionFixture($entityType, $role, self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $user = $context['user'];
        $record = $this->storedRequest($context);
        $carePlanWriter = in_array($role, [Role::DOCTOR, Role::SPECIALIST, Role::MED_COORDINATOR], true);
        $deviceWriter = in_array($role, [Role::DOCTOR, Role::SPECIALIST], true);
        $conclusionWriter = ($entityType === LegalEntity::TYPE_PRIMARY_CARE && $role === Role::DOCTOR)
            || ($entityType === LegalEntity::TYPE_OUTPATIENT && $role === Role::SPECIALIST);

        // Deliberately supply scopes for every action: each domain still enforces its own author role.
        $this->assertSame($carePlanWriter, new CarePlanPolicy()->search($user)->allowed());
        $this->assertSame($carePlanWriter, $user->getCarePlanWriterEmployee() !== null);
        $this->assertTrue(new DeviceRequestRequestPolicy()->viewAny($user)->allowed());
        $this->assertSame($deviceWriter, new DeviceRequestRequestPolicy()->create($user)->allowed());
        $this->assertTrue(new MedicationRequestRequestPolicy()->view($user, $record)->allowed());
        $this->assertSame($conclusionWriter, new CompositionPolicy()->createTempDisability($user)->allowed());
        $this->assertSame(
            $entityType === LegalEntity::TYPE_OUTPATIENT && $role === Role::SPECIALIST,
            new CompositionPolicy()->createNewborn($user)->allowed()
        );
    }

    #[DataProvider('workplaces')]
    public function test_referral_actions_use_each_actual_role_within_the_workplace(string $entityType, Role $role): void
    {
        $context = $this->compositionFixture($entityType, $role, self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $policy = new ServiceRequestRequestPolicy();

        $this->assertTrue($policy->search($context['user'])->allowed());
        $this->assertSame(
            in_array($role, [Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT, Role::MED_COORDINATOR], true),
            $policy->create($context['user'], $context['employee'], 'laboratory_procedure')->allowed()
        );
        $this->assertSame(
            in_array($role, [Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT], true),
            $policy->use($context['user'], $context['employee'], 'laboratory_procedure')->allowed()
        );
        $context['employee']->update(['is_active' => false]);
        $this->assertTrue($policy->create($context['user'], $context['employee'], 'laboratory_procedure')->denied());
        $this->assertTrue($policy->use($context['user'], $context['employee'], 'laboratory_procedure')->denied());
    }

    #[DataProvider('workplaces')]
    public function test_reopened_prescription_action_checks_revoked_scope_with_visible_feedback_and_no_effects(string $entityType, Role $role): void
    {
        $context = $this->compositionFixture($entityType, $role, self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $record = $this->storedRequest($context);
        Cache::forever(MedicalProgramDictionary::KEY, []);
        Cache::forever(MedicalProgramDictionary::KEY.':fresh', true);
        Http::preventStrayRequests();
        Queue::fake();
        $component = Livewire::test(PatientPrescriptionRequestView::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'],
            'preperson' => null, 'requestId' => $record->uuid,
        ])->assertSee($record->requestNumber);
        $original = $record->fresh()->getRawOriginal();
        $context['user']->revokePermissionTo('medication_request_request:read');

        $component->call('refreshDetail')->assertSet('showSignatureModal', false)
            ->assertSee(__('medication-requests.permission_denied'));

        $this->assertSame($original, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_request_requests', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('workplaces')]
    public function test_an_inactive_position_cannot_supply_prescription_or_device_access(string $entityType, Role $role): void
    {
        $context = $this->compositionFixture($entityType, $role, self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $record = $this->storedRequest($context);
        $context['employee']->update(['is_active' => false]);

        $this->assertTrue(new MedicationRequestRequestPolicy()->view($context['user'], $record)->denied());
        $this->assertTrue(new DeviceRequestRequestPolicy()->viewAny($context['user'])->denied());
        $this->assertTrue(new DeviceRequestRequestPolicy()->create($context['user'])->denied());
        $this->assertNull($context['user']->getCarePlanWriterEmployee());
    }

    public static function employmentWindows(): array
    {
        return ['future position' => ['future'], 'ended yesterday' => ['past'], 'ends today' => ['today']];
    }

    #[DataProvider('employmentWindows')]
    public function test_a_stale_active_flag_cannot_override_employment_dates(string $window): void
    {
        $context = $this->compositionFixture(scopes: self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $record = $this->storedRequest($context);
        $context['employee']->update($window === 'future'
            ? ['start_date' => today()->addDay()->toDateString()]
            : ['end_date' => ($window === 'past' ? today()->subDay() : today())->toDateString()]);

        $this->assertSame(null, Repository::employee()->activeForLegalEntity($context['user'], $context['legalEntity'], ['SPECIALIST'])?->id);
        $this->assertNull($context['user']->getCarePlanWriterEmployee());
        $this->assertTrue(new MedicationRequestRequestPolicy()->view($context['user'], $record)->denied());
        $this->assertTrue(new DeviceRequestRequestPolicy()->create($context['user'])->denied());
    }

    public function test_same_party_without_a_link_to_the_user_cannot_supply_the_clinical_employee(): void
    {
        $context = $this->compositionFixture(scopes: self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $record = $this->storedRequest($context);
        $context['user']->employees()->detach($context['employee']->id);
        $context['employee']->update(['user_id' => null]);

        $this->assertSame(null, Repository::employee()->activeForLegalEntity($context['user'], $context['legalEntity'], ['SPECIALIST'])?->id);
        $this->assertNull($context['user']->getCarePlanWriterEmployee());
        $this->assertTrue(new MedicationRequestRequestPolicy()->view($context['user'], $record)->denied());
        $this->assertTrue(new DeviceRequestRequestPolicy()->create($context['user'])->denied());
    }

    public static function validEmployeeLinks(): array
    {
        return ['pivot link' => ['pivot'], 'user foreign key' => ['user'], 'both links' => ['both']];
    }

    #[DataProvider('validEmployeeLinks')]
    public function test_current_employment_accepts_each_supported_explicit_user_link(string $link): void
    {
        $context = $this->compositionFixture(scopes: self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        if ($link === 'pivot') {
            $context['employee']->update(['user_id' => null]);
        } elseif ($link === 'user') {
            $context['user']->employees()->detach($context['employee']->id);
        }
        $context['employee']->update(['start_date' => today()->toDateString(), 'end_date' => today()->addDay()->toDateString()]);

        $this->assertSame(
            $context['employee']->id,
            Repository::employee()->activeForLegalEntity($context['user'], $context['legalEntity'], ['SPECIALIST'])?->id
        );
        $this->assertTrue(new DeviceRequestRequestPolicy()->create($context['user'])->allowed());
    }

    public function test_foreign_and_inactive_earlier_positions_do_not_replace_the_current_eligible_employee(): void
    {
        $context = $this->compositionFixture(scopes: self::SCOPES);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $entity = $context['legalEntity'];
        $context['employee']->update(['is_active' => false]);
        $foreign = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $entity->legalEntityTypeId, 'is_active' => true,
        ]);
        $attributes = $context['employee']->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        Employee::create(array_replace($attributes, [
            'uuid' => (string) Str::uuid(), 'is_active' => true, 'legal_entity_id' => $foreign->id,
        ]));
        $eligible = Employee::create(array_replace($attributes, ['uuid' => (string) Str::uuid(), 'is_active' => true]));
        $context['user']->employees()->attach($eligible->id);

        $this->assertSame(
            $eligible->id,
            Repository::employee()->activeForLegalEntity($context['user'], $entity, ['SPECIALIST'])?->id
        );
        $this->assertNull(Repository::employee()->activeForLegalEntity(
            $context['user'],
            $entity,
            ['SPECIALIST'],
            $context['employee']->uuid
        ));
    }

    public function test_care_plan_author_cannot_use_an_earlier_position_whose_role_is_unavailable_in_this_login(): void
    {
        $context = $this->compositionFixture(scopes: self::SCOPES);
        $earlierDoctor = $context['employee'];
        $attributes = $earlierDoctor->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $earlierDoctor->update(['employee_type' => Role::DOCTOR->value]);
        $specialist = Employee::create(array_replace($attributes, ['uuid' => (string) Str::uuid()]));
        $context['user']->employees()->attach($specialist->id);
        $this->grantReferralEmployeeRole($context['user'], $specialist);

        $this->assertTrue($context['user']->hasRole(Role::SPECIALIST->value));
        $this->assertFalse($context['user']->hasRole(Role::DOCTOR->value));
        $this->assertSame($specialist->id, $context['user']->getCarePlanWriterEmployee()?->id);
        $this->assertNull($context['user']->getCarePlanWriterEmployee(employeeUuid: $earlierDoctor->uuid));
    }

    private function storedRequest(array $context): MedicationRequestRequest
    {
        $native = json_decode(
            file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        )['request_detail'];
        $native['id'] = (string) Str::uuid();
        $native['person']['id'] = $context['person']->uuid;
        $native['employee']['id'] = $context['employee']->uuid;
        $native['legal_entity']['id'] = $context['legalEntity']->uuid;

        return MedicalEventsRepository::medicationRequest()->storeResource(
            new MedicationRequestMapper()->fromEHealth($native),
            $context['person']->id,
            employeeId: $context['employee']->id,
            resourceType: MedicationRequestRequest::TYPE_REQUEST,
            native: $native
        );
    }
}
