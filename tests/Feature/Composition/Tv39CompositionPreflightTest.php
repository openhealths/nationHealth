<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionType;
use App\Jobs\CompositionAsyncProcessing;
use App\Livewire\Composition\CompositionTempDisabilityCreate;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Tv39CompositionPreflightTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    public static function refusals(): array
    {
        return [
            'verification' => ['verification', 'participant_verification_not_allowed'],
            'tax identity' => ['tax', 'participant_tax_id_required'],
            'foreign document' => ['foreign_document', 'participant_documents_not_allowed'],
            'allowed document' => ['document', 'participant_documents_not_allowed'],
            'focus age' => ['age', 'participant_age_not_allowed'],
            'focus gender' => ['gender', 'participant_gender_not_allowed'],
            'preperson state' => ['inactive', 'participant_status_not_allowed'],
            'preperson type' => ['type', 'participant_type_not_allowed'],
            'foreign preperson' => ['foreign', 'foreign_preperson_not_allowed'],
            'author speciality' => ['speciality', 'author_speciality_not_allowed'],
            'disability speciality' => ['disability_speciality', 'author_speciality_not_allowed'],
            'identified status' => ['identified_status', 'participant_status_not_allowed'],
            'additional speciality' => ['additional_speciality', 'author_speciality_not_allowed'],
            'custodian state' => ['custodian', 'author_not_found'],
            'encounter type' => ['encounter_type', 'encounter_type_not_allowed'],
            'required division' => ['division', 'encounter_division_required'],
            'changed subject' => ['subject', 'participant_not_found'],
            'changed encounter' => ['encounter', 'encounter_not_selectable'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_preflight_refuses_invalid_context_before_post_storage_or_queue(string $case, string $error): void
    {
        [$component, $fixture, $child] = $this->readyChildCare();
        $settings = config('compositions.emal');
        switch ($case) {
            case 'verification': $fixture['person']->update(['verification_status' => 'NOT_VERIFIED']);
                break;
            case 'tax': $fixture['person']->update(['tax_id' => null]);
                break;
            case 'foreign_document': $fixture['person']->documents()->update(['type' => 'FOREIGN_PASSPORT']);
                break;
            case 'document': $settings[CompositionConfiguration::FOCUS_DOCUMENTS->value] = ['CHILD_CARE' => ['BIRTH_CERTIFICATE']];
                break;
            case 'age': $settings[CompositionConfiguration::FOCUS_AGES->value]['CHILD_CARE'] = [70, null];
                break;
            case 'gender': $settings[CompositionConfiguration::FOCUS_GENDERS->value] = ['CHILD_CARE' => ['MALE']];
                break;
            case 'inactive': $child->update(['status' => 'inactive']);
                break;
            case 'type': $settings[CompositionConfiguration::SUBJECT_TYPES->value]['preperson'] = ['SICKNESS'];
                break;
            case 'foreign': $component->set('form.isForeignTreatment', true);
                break;
            case 'speciality': $fixture['employee']->specialities()->delete();
                break;
            case 'disability_speciality': $settings[CompositionConfiguration::DISABILITY_DOCTOR_SPECIALITIES_ENABLED->value] = true;
                $settings[CompositionConfiguration::DISABILITY_DOCTOR_SPECIALITIES->value] = ['CHILD_CARE' => ['FAMILY_DOCTOR']];
                break;
            case 'identified_status': $settings[CompositionConfiguration::FOCUS_STATUSES->value]['CHILD_CARE'] = 'inactive';
                break;
            case 'additional_speciality': $settings[CompositionConfiguration::AUTHOR_SPECIALITIES_ENABLED->value] = true;
                break;
            case 'custodian': $fixture['legalEntity']->update(['status' => 'SUSPENDED']);
                break;
            case 'encounter_type': $settings[CompositionConfiguration::DISABILITY_ENCOUNTER_TYPES->value] = ['virtual'];
                break;
            case 'division': $settings[CompositionConfiguration::DISABILITY_DIVISION_ENABLED->value] = true;
                break;
            case 'subject': $component->set('form.subjectUuid', (string) Str::uuid());
                break;
            case 'encounter': $component->set('form.encounterUuid', (string) Str::uuid());
                break;
        }
        config(['compositions.emal' => $settings]);
        Bus::fake();
        $component->call('submitComposition')->assertHasErrors('form.guard')
            ->assertSee(__('compositions.errors.'.$error))->assertSet('asyncJobId', null);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
        $this->assertSame(0, CompositionOperation::count());
        $this->assertSame(0, Composition::count());
        Bus::assertNothingDispatched();
    }

    public function test_valid_child_caregiver_context_is_accepted(): void
    {
        [$component] = $this->readyChildCare();
        $component->call('submitComposition')->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
        Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
        $this->assertSame(1, CompositionOperation::count());
    }

    public static function disabilitySpecialityCases(): array
    {
        return ['mapped allowed' => [true, ['CHILD_CARE' => ['OBSTETRICS_AND_GYNECOLOGY']]],
            'disabled' => [false, ['CHILD_CARE' => ['FAMILY_DOCTOR']]],
            'unmapped category' => [true, ['QUARANTINE' => ['FAMILY_DOCTOR']]]];
    }

    #[DataProvider('disabilitySpecialityCases')]
    public function test_disability_speciality_filter_applies_only_enabled_category_restrictions(bool $enabled, array $map): void
    {
        [$component] = $this->readyChildCare();
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::DISABILITY_DOCTOR_SPECIALITIES_ENABLED->value] = $enabled;
        $settings[CompositionConfiguration::DISABILITY_DOCTOR_SPECIALITIES->value] = $map;
        config(['compositions.emal' => $settings]);
        $component->call('submitComposition')->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
        $this->assertSame(1, CompositionOperation::count());
    }

    public static function statusReadFailures(): array
    {
        return ['not found' => [404, ['error' => ['type' => 'not_found', 'message' => 'Person not found']], 'participant_status_unavailable'],
            'malformed' => [200, ['data' => [['type' => 'OTP']]], 'invalid_response']];
    }

    #[DataProvider('statusReadFailures')]
    public function test_fresh_identified_person_eligibility_read_must_succeed_before_creation(int $status, array $body, string $error): void
    {
        [$component] = $this->readyChildCare();
        $this->fakeEHealth(['*authentication_methods*' => Http::response($body, $status)]);
        Bus::fake();
        $component->call('submitComposition')->assertSee(__('compositions.errors.'.$error))->assertSet('asyncJobId', null);
        Http::assertSent(static fn ($request): bool => $request->method() === 'GET' && str_contains($request->url(), '/authentication_methods'));
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
        $this->assertSame(0, CompositionOperation::count());
        $this->assertSame(0, Composition::count());
        Bus::assertNothingDispatched();
    }

    public function test_retry_queues_only_the_existing_operation_and_denial_has_no_effects(): void
    {
        [$component, $fixture, $child] = $this->readyChildCare();
        $operation = Repository::compositionOperation()->store(['id' => (string) Str::uuid(), 'status' => 'PENDING'], CompositionAsyncOperation::CREATE, $child, [
            'composition_type' => CompositionType::TEMP_DISABILITY, 'author_uuid' => $fixture['employee']->uuid,
            'encounter_uuid' => $component->get('form.encounterUuid'), 'episode_uuid' => $component->get('episodeUuid'),
        ]);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        Bus::fake();
        Http::fake();
        $component = Livewire::withQueryParams(['job' => $operation->remoteJobId])
            ->test(CompositionTempDisabilityCreate::class, ['legalEntity' => $fixture['legalEntity'], 'preperson' => $child]);
        $component->call('retryAsyncJob');
        Bus::assertBatched(static fn ($batch): bool => $batch->jobs->count() === 1 && $batch->jobs->first() instanceof CompositionAsyncProcessing);
        $this->assertSame(1, CompositionOperation::count());
        Http::assertNothingSent();
        Bus::fake();
        $fixture['employee']->update(['is_active' => false]);
        $component->call('retryAsyncJob')->assertSee(__('compositions.errors.create_not_allowed'));
        Bus::assertNothingDispatched();
        $this->assertSame(1, CompositionOperation::count());
        Http::assertNothingSent();
    }

    public static function invalidAuthenticationMethods(): array
    {
        return ['audit metadata' => ['metadata'], 'expired' => ['expired'], 'inactive' => ['inactive'], 'offline union' => ['offline']];
    }

    #[DataProvider('invalidAuthenticationMethods')]
    public function test_native_authentication_refusal_keeps_management_and_reload_available(string $case): void
    {
        [$component, $fixture, $child] = $this->readyChildCare();
        $method = $this->nativeAuthenticationMethod((string) Str::uuid());
        match ($case) {
            'metadata' => $method['inserted_by'] = null,
            'expired' => $method['ended_at'] = now()->subDay()->toIso8601String(),
            'inactive' => $method['is_active'] = false,
            'offline' => $method['type'] = 'OFFLINE',
        };
        $this->fakeEHealth(['*authentication_methods*' => Http::response(['data' => [$method]])]);
        $component->set('step', CompositionTempDisabilityCreate::STEP_AUTH_METHOD)
            ->call('refreshAuthenticationMethods')->assertSet('authMethods', [])
            ->assertSee(route('persons.patient-data', [$fixture['legalEntity'], 'person' => $fixture['person']->id]));
        $otp = $this->nativeAuthenticationMethod((string) Str::uuid());
        $this->fakeEHealth(['*authentication_methods*' => Http::response(['data' => [$otp]])]);
        $component->call('refreshAuthenticationMethods')->call('selectAuthMethod', $otp['id'])
            ->assertSet('form.informWithUuid', $otp['id'])->assertSet('form.subjectUuid', $child->uuid);
    }

    public static function diagnosisCases(): array
    {
        return ['allowed' => ['allowed', true], 'disallowed code' => ['code', false],
            'two primary diagnoses' => ['multiple', false], 'malformed native response' => ['malformed', false],
            'changed author' => ['author', false]];
    }

    #[DataProvider('diagnosisCases')]
    public function test_enabled_diagnosis_filter_reads_a_complete_native_encounter(string $case, bool $allowed): void
    {
        [$component, $fixture] = $this->readyChildCare();
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::DISABILITY_DIAGNOSES_ENABLED->value] = true;
        $settings[CompositionConfiguration::DISABILITY_DIAGNOSES->value] = ['CHILD_CARE' => ['A00']];
        config(['compositions.emal' => $settings]);
        $native = $this->compositionEncounter($component->get('form.encounterUuid'), $fixture['employee']->uuid);
        foreach (['performer', 'episode', 'visit'] as $reference) {
            $native[$reference]['display_value'] = 'Test reference';
        }
        $native['reasons'] = [];
        $native['actions'] = [];
        $diagnosis = ['condition' => ['display_value' => 'Test condition'],
            'code' => ['coding' => [['system' => 'eHealth/ICD10', 'code' => 'A00']]],
            'role' => ['coding' => [['system' => 'eHealth/diagnosis_roles', 'code' => 'primary']]]];
        $native['diagnoses'] = [$diagnosis];
        match ($case) {
            'allowed' => null,
            'code' => $native['diagnoses'][0]['code']['coding'][0]['code'] = 'B00',
            'multiple' => $native['diagnoses'][] = $diagnosis,
            'malformed' => $native['performer']['identifier']['value'] = null,
            'author' => $native['performer']['identifier']['value'] = (string) Str::uuid(),
        };
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]),
            '*encounters/*' => Http::response(['data' => $native]),
            '*/composition' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
        ]);
        Bus::fake();
        $component->call('submitComposition');
        if ($allowed) {
            $component->assertHasNoErrors()->assertSet('asyncJobStatus', 'PENDING');
            Http::assertSent(static fn ($request): bool => $request->method() === 'POST');
        } else {
            $component->assertHasErrors()->assertSet('asyncJobId', null);
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
            Bus::assertNothingDispatched();
            $this->assertSame(0, CompositionOperation::count());
        }
    }

    private function readyChildCare(): array
    {
        $fixture = $this->compositionFixture();
        $child = $this->compositionPreperson();
        $encounter = (string) Str::uuid();
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]),
            '*searchComposition*' => Http::response(['data' => []]),
            '*/composition' => Http::response(['data' => ['id' => (string) Str::uuid(), 'status' => 'PENDING', 'eta' => now()->toIso8601String(), 'links' => []]]),
            '*' => Http::response($this->eHealthBody([$this->compositionEncounter($encounter, $fixture['employee']->uuid)])),
        ]);
        $component = Livewire::test(CompositionTempDisabilityCreate::class, ['legalEntity' => $fixture['legalEntity'], 'preperson' => $child])
            ->set('form.category', 'CHILD_CARE')->call('selectFocus', $fixture['person']->id)
            ->call('selectEncounter', $encounter)->call('skipAuthMethod')
            ->set('form.eventPeriodStart', today()->format('d.m.Y'))->set('form.eventPeriodEnd', today()->format('d.m.Y'));

        return [$component, $fixture, $child];
    }
}
