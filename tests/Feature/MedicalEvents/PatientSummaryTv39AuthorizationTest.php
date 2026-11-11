<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use App\Classes\eHealth\Api\Patient\Procedure as ProcedureApi;
use App\Enums\User\Role;
use App\Livewire\Components\XMessage;
use App\Livewire\DiagnosticReport\Forms\DiagnosticReportForm;
use App\Livewire\EncounterPackage\Forms\ConditionForm;
use App\Livewire\EncounterPackage\Forms\DetectedIssueForm;
use App\Livewire\EncounterPackage\Forms\DeviceAssociationForm;
use App\Livewire\EncounterPackage\Forms\DeviceForm;
use App\Livewire\EncounterPackage\Forms\EncounterForm;
use App\Livewire\EncounterPackage\Forms\ImmunizationForm;
use App\Livewire\EncounterPackage\Forms\ObservationForm;
use App\Livewire\EncounterPackage\Forms\ProcedureForm;
use App\Livewire\Person\Records\PatientSummary;
use App\Livewire\Procedure\Forms\ProcedureForm as StandaloneProcedureForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Role as PermissionRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientSummaryTv39AuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('summaryActions')]
    public function test_summary_actions_deny_missing_scope_before_api_database_or_queue_effects(string $action): void
    {
        $this->context(false);
        Http::preventStrayRequests();
        Queue::fake();
        $component = new PatientSummary();
        // No patient context is deliberately supplied: an unauthorized action must return before loading it.
        $component->{$action}();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Livewire::test(XMessage::class)->assertSee(__('patients.policy.view_summary'));
    }

    public static function summaryActions(): array
    {
        $entities = ['Episodes', 'Encounters', 'ClinicalImpressions', 'Immunizations', 'Observations', 'Diagnoses', 'Conditions', 'DiagnosticReports', 'Devices', 'Procedures'];
        $actions = ['syncCarePlans'];
        foreach ($entities as $entity) {
            $actions[] = 'sync'.$entity;
            $actions[] = 'get'.$entity;
        }

        return array_combine($actions, array_map(static fn (string $action): array => [$action], $actions));
    }

    #[DataProvider('legalEntityTypes')]
    public function test_assistant_summary_scope_allows_local_reads_without_full_entity_scopes(string $entityType): void
    {
        $user = $this->context(true, $entityType);
        $this->assertTrue($user->isAssistantOnly());
        $this->assertFalse($user->can('immunization:read'));
        $patient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $component = new PatientSummary();
        $component->personId = $patient->id;
        Http::preventStrayRequests();
        $component->getImmunizations();
        $this->assertSame([], $component->immunizations);
        $this->assertNull(session('error'));
        Http::assertNothingSent();

        $user->revokePermissionTo('patient_summary:read');
        $this->actingAs($user->fresh());
        $component->syncCarePlans();
        Http::assertNothingSent();
        $this->assertSame(__('patients.policy.view_summary'), session('error'));
    }

    public static function legalEntityTypes(): array
    {
        return ['PMD' => ['PRIMARY_CARE'], 'SMD' => ['OUTPATIENT']];
    }

    #[DataProvider('primarySourceForms')]
    public function test_actual_forms_require_assistant_primary_source_and_preserve_immunization_exception(string $formClass, string $property, bool $collection, bool $exception): void
    {
        $this->context(true);
        $component = new NursingFormContext();
        $component->conditionForm = new ConditionForm($component, 'conditionForm');
        $component->form = new EncounterForm($component, 'form');
        $form = new $formClass($component, 'clinicalForm');
        $field = $property.($collection ? '.0' : '').'.primarySource';
        $form->{$property} = $collection ? [['primarySource' => true]] : ['primarySource' => true];
        $form->validateOnly($field);
        $form->{$property} = $collection ? [['primarySource' => false]] : ['primarySource' => false];
        if ($exception) {
            $form->validateOnly($field);
            $this->assertTrue(true);

            return;
        }

        try {
            $form->validateOnly($field);
            $this->fail('A secondary-source nursing record must fail actual form validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('clinicalForm.'.$field, $exception->errors());
        }
    }

    public static function primarySourceForms(): array
    {
        return [
            'condition' => [ConditionForm::class, 'conditions', true, false],
            'observation' => [ObservationForm::class, 'observations', true, false],
            'device' => [DeviceForm::class, 'devices', true, false],
            'device association' => [DeviceAssociationForm::class, 'deviceAssociations', true, false],
            'detected issue' => [DetectedIssueForm::class, 'detectedIssues', true, false],
            'package procedure' => [ProcedureForm::class, 'procedures', true, false],
            'standalone procedure' => [StandaloneProcedureForm::class, 'procedure', false, false],
            'diagnostic report observation' => [DiagnosticReportForm::class, 'observations', true, false],
            'diagnostic report' => [DiagnosticReportForm::class, 'diagnosticReport', false, false],
            'immunization' => [ImmunizationForm::class, 'immunizations', true, true],
        ];
    }

    public function test_procedure_summary_validates_and_stores_every_page_before_reporting_success(): void
    {
        $this->context(true);
        $patient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $first = $this->procedureSummaryRecord();
        $second = $this->procedureSummaryRecord();
        $this->fakeProcedurePages([$first], [$second]);
        $component = new PatientSummary();
        $component->personId = $patient->id;
        $component->uuid = $patient->uuid;
        $component->syncProcedures();
        $this->assertSame(2, Procedure::wherePersonId($patient->id)->count());
        $this->assertSame(__('procedures.messages.synced_successfully'), session('success'));
        $this->assertCount(2, Http::recorded(static fn ($request): bool => str_contains($request->url(), '/summary/procedures')));
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/summary/procedures') && $request['page'] === 2);
    }

    public function test_invalid_later_procedure_page_does_not_persist_the_first_page_or_report_success(): void
    {
        $this->context(true);
        $patient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $first = $this->procedureSummaryRecord();
        $second = $this->procedureSummaryRecord();
        $second['primary_source'] = 'true';
        $this->fakeProcedurePages([$first], [$second]);
        $component = new PatientSummary();
        $component->personId = $patient->id;
        $component->uuid = $patient->uuid;
        $component->syncProcedures();
        $this->assertSame(0, Procedure::wherePersonId($patient->id)->count());
        $this->assertNull(session('success'));
        $this->assertNotNull(session('error'));
    }

    private function fakeProcedurePages(array $first, array $second): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::sequence()
            ->push(['data' => $first, 'paging' => ['page' => 1, 'page_size' => 1, 'total_entries' => 2, 'total_pages' => 2]])
            ->push(['data' => $second, 'paging' => ['page' => 2, 'page_size' => 1, 'total_entries' => 2, 'total_pages' => 2]])]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new ProcedureApi($factory);
        $api->stub($stubs)->preventStrayRequests();
        $this->instance(ProcedureApi::class, $api);
    }

    /** Native Summary Procedures schema, PREPROD Medical Events 9.15.6. */
    private function procedureSummaryRecord(): array
    {
        $reference = static fn (string $type): array => [
            'identifier' => ['value' => (string) Str::uuid(), 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]],
            'display_value' => 'Summary record',
        ];

        return [
            'id' => (string) Str::uuid(), 'status' => 'completed', 'primary_source' => true,
            'performed_date_time' => '2026-11-11T09:00:00Z', 'code' => $reference('service'),
            'recorded_by' => $reference('employee'), 'performer' => [$reference('employee')],
            'managing_organization' => $reference('legal_entity'), 'encounter' => $reference('encounter'),
            'category' => ['coding' => [['system' => 'eHealth/procedure_categories', 'code' => 'laboratory_procedure']]],
        ];
    }

    private function context(bool $withScope, string $entityType = 'PRIMARY_CARE'): User
    {
        $typeId = DB::table('legal_entity_types')->where('name', $entityType)->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => $entityType]);
        $entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $entity);
        setPermissionsTeamId($entity->id);
        session(['single_role_login' => true]);
        $party = Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Nursing', 'last_name' => 'Author',
            'tax_id' => '3344556677', 'birth_date' => '1975-01-01', 'gender' => 'MALE',
        ]);
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'email' => 'nursing_'.Str::random(8).'@example.com',
            'password' => Hash::make('password'), 'party_id' => $party->id,
        ]);
        if ($withScope) {
            $permission = Permission::findOrCreate('patient_summary:read', 'web');
            $permission->legalEntityTypes()->syncWithoutDetaching([$typeId]);
            $role = PermissionRole::findOrCreate(Role::ASSISTANT->value, 'web');
            $role->syncPermissions($permission);
            $user->givePermissionToParent($permission);
            $user->assignRoleParent($role);
        }
        $user = $user->fresh();
        $this->actingAs($user);

        return $user;
    }
}

class NursingFormContext extends Component
{
    public string $employeeType = 'ASSISTANT';

    public ConditionForm $conditionForm;

    public EncounterForm $form;
}
