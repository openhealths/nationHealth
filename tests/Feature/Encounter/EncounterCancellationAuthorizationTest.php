<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Core\BaseForm;
use App\Enums\User\Role;
use App\Livewire\Components\XMessage;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Role as PermissionRole;
use App\Models\User;
use App\Policies\EncounterPolicy;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\HandlesEncounterCancellation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class EncounterCancellationAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_author_can_read_completion_after_the_encounter_is_cancelled_but_cannot_cancel_again(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        $policy = new EncounterPolicy();
        $this->assertTrue($policy->cancel($user, $encounter)->allowed());

        $encounter->update(['status' => 'entered_in_error']);
        $this->assertTrue($policy->viewCancellation($user, $encounter->fresh())->allowed());
        $this->assertTrue($policy->cancel($user, $encounter->fresh())->denied());
    }

    public function test_other_employee_cannot_poll_the_authors_cancellation(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        $encounter->performer->update(['value' => (string) Str::uuid()]);
        $this->assertTrue(new EncounterPolicy()->viewCancellation($user, $encounter->fresh())->denied());
    }

    public function test_inactive_author_cannot_cancel_or_read_a_cancellation(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        $employee->update(['is_active' => false]);
        $this->assertTrue(new EncounterPolicy()->cancel($user, $encounter)->denied());
        $this->assertTrue(new EncounterPolicy()->viewCancellation($user, $encounter)->denied());
    }

    public function test_foreign_managing_organization_is_denied_even_for_the_performer(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        Episode::whereUuid($encounter->episode->value)->firstOrFail()->managingOrganization->update(['value' => (string) Str::uuid()]);
        $this->assertTrue(new EncounterPolicy()->viewCancellation($user, $encounter)->denied());
    }

    public function test_assistant_can_cancel_only_their_own_record_and_administrator_position_does_not_override_it(): void
    {
        [$user, $employee, $patient, $encounter, $entity] = $this->context(Role::ASSISTANT->value);
        Permission::findByName('encounter:cancel', 'web')->legalEntityTypes()->syncWithoutDetaching([$entity->legalEntityTypeId]);
        $role = PermissionRole::findOrCreate(Role::ASSISTANT->value, 'web');
        $role->syncPermissions('encounter:cancel');
        $user->assignRoleParent($role);
        $user = $user->fresh();
        $this->actingAs($user);
        $this->assertTrue($user->isAssistantOnly());
        $this->assertTrue(new EncounterPolicy()->cancel($user, $encounter)->allowed());

        Employee::create([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Administrator', 'employee_type' => Role::MED_ADMIN->value,
            'status' => 'APPROVED', 'legal_entity_id' => $entity->id, 'is_active' => true,
            'position' => 'Administrator', 'start_date' => now()->toDateString(), 'user_id' => $user->id, 'party_id' => $user->partyId,
        ]);
        $encounter->performer->update(['value' => (string) Str::uuid()]);
        $this->assertTrue(new EncounterPolicy()->cancel($user, $encounter->fresh())->denied());
    }

    public function test_denied_action_has_visible_feedback_without_api_database_or_queue_effects(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        $employee->update(['is_active' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        $component = $this->cancellationComponent($patient);
        $component->openEncounterCancellation($encounter->uuid);

        $this->assertFalse($component->showCancellationModal);
        $this->assertSame('finished', $encounter->fresh()->status->value);
        $this->assertSame(0, $encounter->ehealthLinks()->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Livewire::test(XMessage::class)->assertSee(__('encounters.policy.cancel'));
    }

    public function test_reopened_page_keeps_the_job_visible_when_the_worker_finishes_between_polls(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        $submission = Repository::encounter()->startCancellation($encounter, [
            'operation' => 'cancelEncounter', 'selectedRecords' => [], 'explanatoryLetter' => 'Помилка',
            'cancellationReasonConcept' => ['coding' => [['system' => 'eHealth/cancellation_reasons', 'code' => 'technical_error']]],
        ]);
        $component = Livewire::test(CancellationTestComponent::class, ['patientId' => $patient->id]);
        $component->assertSet('cancellationSubmissionIds', [$submission->id]);
        Repository::encounter()->finishCancellation($submission, ['status' => 'processed']);
        Http::preventStrayRequests();

        $component->call('pollEncounterCancellation')
            ->assertSet('refreshCount', 1)
            ->assertSet('cancellationSubmissionIds', [])
            ->assertSee(__('encounters.messages.cancel_confirmed'));
        Http::assertNothingSent();
        $component->call('pollEncounterCancellation')->assertSet('refreshCount', 1);
    }

    public function test_signing_field_errors_keep_the_modal_open_and_do_not_submit(): void
    {
        [$user, $employee, $patient, $encounter] = $this->context();
        $component = $this->cancellationComponent($patient);
        $component->actionType = 'cancel_encounter';
        $component->showSignatureModal = true;
        $component->cancellationForm->cancellingId = $encounter->uuid;
        Http::preventStrayRequests();
        Queue::fake();

        $component->cancelSelectedEncounter();

        $this->assertTrue($component->showSignatureModal);
        $this->assertNotEmpty($component->getErrorBag()->all());
        $this->assertSame(0, $encounter->ehealthLinks()->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Livewire::test(XMessage::class)->assertSee(session('error'));
    }

    private function cancellationComponent(Person $patient): CancellationTestComponent
    {
        $component = new CancellationTestComponent();
        $component->patientModel = $patient;
        $component->form = new BaseForm($component, 'form');
        $component->cancellationForm = new EncounterCancellationForm($component, 'cancellationForm');

        return $component;
    }

    private function context(string $employeeType = 'DOCTOR'): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $entity);
        $party = Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Cancellation', 'last_name' => 'Author',
            'tax_id' => '3344556677', 'birth_date' => '1975-01-01', 'gender' => 'MALE',
        ]);
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'email' => 'cancel_'.Str::random(8).'@example.com',
            'password' => Hash::make('password'), 'party_id' => $party->id,
        ]);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Cancellation Author', 'employee_type' => $employeeType,
            'status' => 'APPROVED', 'legal_entity_id' => $entity->id, 'is_active' => true,
            'position' => 'Clinical worker', 'start_date' => now()->toDateString(), 'user_id' => $user->id, 'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);
        $this->grantMedicalEventAbilities($user, ['encounter:cancel', 'encounter:read']);
        $this->actingAs($user);
        $patient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $episode = Episode::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $patient->id, 'name' => 'Episode', 'status' => 'active',
            'managing_organization_id' => Identifier::create(['value' => $entity->uuid])->id,
        ]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $patient->id, 'status' => 'finished',
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'class_id' => Repository::coding()->store(['system' => 'eHealth/encounter_classes', 'code' => 'AMB'])->id,
            'type_id' => Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'consultation']]])->id,
        ]);

        return [$user, $employee, $patient, $encounter, $entity];
    }
}

class CancellationTestComponent extends Component
{
    use HandlesEncounterCancellation;

    public Person $patientModel;

    public BaseForm $form;

    public EncounterCancellationForm $cancellationForm;

    public int $refreshCount = 0;

    public function mount(int $patientId): void
    {
        $this->patientModel = Person::findOrFail($patientId);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
                @foreach ($this->pendingEncounterCancellations as $submission)
                    <span wire:key="cancel-{{ $submission->id }}">{{ $submission->status }}</span>
                @endforeach
            </div>
            BLADE;
    }

    public function patient(): Person
    {
        return $this->patientModel;
    }

    protected function encounterCancellationForm(): EncounterCancellationForm
    {
        return $this->cancellationForm;
    }

    protected function afterEncounterCancelled(): void
    {
        $this->refreshCount++;
    }
}
