<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionType;
use App\Enums\User\Role;
use App\Livewire\Composition\CompositionTempDisabilityCreate;
use App\Livewire\Person\Records\PatientCompositions;
use App\Repositories\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class Tv39CompositionWorkflowTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    public function test_future_employee_contract_is_not_a_composition_author(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->compositionFixture();
        $employee->update(['start_date' => today()->addDay()->format('Y-m-d')]);
        $this->assertNull($user->getCompositionAuthorEmployee(CompositionType::TEMP_DISABILITY));
    }

    public function test_unlinked_same_party_employee_is_not_a_composition_author(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->compositionFixture();
        $user->employees()->detach($employee->id);
        $employee->update(['user_id' => null]);
        $this->assertNull($user->getCompositionAuthorEmployee(CompositionType::TEMP_DISABILITY));
    }

    public function test_linked_employee_requires_an_available_current_role(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->compositionFixture();
        $user->removeRole(Role::SPECIALIST->value);
        $this->assertNull($user->fresh()->getCompositionAuthorEmployee(CompositionType::TEMP_DISABILITY));
    }

    public function test_unavailable_earlier_doctor_does_not_hide_current_specialist(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->compositionFixture();
        $doctor = $employee->replicate();
        $doctor->uuid = (string) Str::uuid();
        $doctor->employee_type = Role::DOCTOR->value;
        $doctor->save();
        $user->employees()->attach($doctor->id);
        $this->assertSame([$employee->uuid], $user->getCompositionEmployeeUuids());
    }

    public function test_authentication_fallback_can_reload_a_new_otp_without_changing_subject(): void
    {
        ['legalEntity' => $entity, 'person' => $caregiver] = $this->compositionFixture();
        $child = $this->compositionPreperson();
        $this->fakeEHealth(['*authentication_methods*' => Http::response(['data' => []])]);
        $component = Livewire::test(CompositionTempDisabilityCreate::class, ['legalEntity' => $entity, 'preperson' => $child])
            ->set('form.category', 'CHILD_CARE')->call('selectFocus', $caregiver->id)
            ->assertSet('authMethods', [])
            ->assertSee(route('persons.patient-data', [$entity, 'person' => $caregiver->id]));
        $method = ['id' => (string) Str::uuid(), 'type' => 'OTP', 'phone_number' => '+380501234567',
            'inserted_at' => '2026-11-11T10:00:00Z', 'inserted_by' => (string) Str::uuid()];
        $this->fakeEHealth(['*authentication_methods*' => Http::response(['data' => [$method]])]);
        $component->call('refreshAuthenticationMethods')->call('selectAuthMethod', $method['id'])
            ->assertSet('form.informWithUuid', $method['id'])->assertSet('form.subjectUuid', $child->uuid)
            ->assertSet('form.sectionFocusUuid', $caregiver->uuid);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), $caregiver->uuid.'/authentication_methods'));
    }

    public function test_care_conclusion_keeps_child_subject_and_uses_identified_caregiver_focus(): void
    {
        ['legalEntity' => $entity, 'employee' => $employee, 'person' => $caregiver] = $this->compositionFixture();
        $child = $this->compositionPreperson();
        $encounterUuid = (string) Str::uuid();
        $this->fakeEHealth([
            '*authentication_methods*' => Http::response(['data' => []]),
            '*' => Http::response($this->eHealthBody([$this->compositionEncounter($encounterUuid, $employee->uuid)])),
        ]);
        $component = Livewire::test(CompositionTempDisabilityCreate::class, ['legalEntity' => $entity, 'preperson' => $child])
            ->set('form.category', 'CHILD_CARE')
            ->call('selectFocus', $caregiver->id)
            ->assertSet('form.isUnidentified', true)
            ->assertSet('form.isFocusUnidentified', false)
            ->assertSet('form.subjectUuid', $child->uuid)
            ->assertSet('form.sectionFocusUuid', $caregiver->uuid)
            ->assertSee($caregiver->fullName);
        $payload = $component->instance()->form->toPayload($employee->uuid);
        $this->assertSame('preperson', data_get($payload, 'subject.type.coding.0.code'));
        $this->assertSame('person', data_get($payload, 'section.focus.type.coding.0.code'));
        $this->assertSame($child->uuid, data_get($payload, 'subject.value'));
        $this->assertSame($caregiver->uuid, data_get($payload, 'section.focus.value'));
        $this->assertSame(route('persons.patient-data', [$entity, 'person' => $caregiver->id]), $component->instance()->authenticationManagementUrl());
    }

    public function test_changing_back_to_sickness_resets_focus_and_authentication_context(): void
    {
        ['legalEntity' => $entity, 'person' => $caregiver] = $this->compositionFixture();
        $child = $this->compositionPreperson();
        $this->fakeEHealth(['*' => Http::response(['data' => []])]);
        Livewire::test(CompositionTempDisabilityCreate::class, ['legalEntity' => $entity, 'preperson' => $child])
            ->set('form.category', 'CHILD_CARE')->call('selectFocus', $caregiver->id)
            ->set('form.category', 'SICKNESS')
            ->assertSet('form.sectionFocusUuid', $child->uuid)
            ->assertSet('form.isFocusUnidentified', true)
            ->assertSet('form.informWithUuid', null)->assertSet('authMethods', []);
    }

    public function test_own_finished_encounter_on_second_page_is_offered(): void
    {
        ['legalEntity' => $entity, 'employee' => $employee] = $this->compositionFixture();
        $mine = (string) Str::uuid();
        $this->fakeEHealth(['*' => function ($request) use ($mine, $employee) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $encounter = $this->compositionEncounter($page === 2 ? $mine : (string) Str::uuid(), $page === 2 ? $employee->uuid : (string) Str::uuid());

            return Http::response(['data' => [$encounter], 'paging' => ['page_number' => $page, 'page_size' => 1, 'total_entries' => 2, 'total_pages' => 2]]);
        }]);
        $offered = Livewire::test(CompositionTempDisabilityCreate::class, ['legalEntity' => $entity, 'preperson' => $this->compositionPreperson()])->instance()->availableEncounters;
        $this->assertCount(1, $offered);
        $this->assertSame($mine, $offered->first()['uuid']);
        Http::assertSentCount(2);
    }

    public function test_author_positions_follow_configuration_without_a_fixed_allowlist(): void
    {
        ['employee' => $employee, 'user' => $user] = $this->compositionFixture();
        $employee->update(['position' => 'P120']);
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::AUTHOR_POSITIONS->value]['P120'] = ['LIVE_BIRTH'];
        config(['compositions.emal' => $settings]);
        $this->assertSame($employee->uuid, $user->getCompositionAuthorEmployee(CompositionType::NEWBORN)?->uuid);
        unset($settings[CompositionConfiguration::AUTHOR_POSITIONS->value]['P120']);
        config(['compositions.emal' => $settings]);
        $this->assertNull($user->getCompositionAuthorEmployee(CompositionType::NEWBORN));
    }

    public function test_missing_configuration_denies_newborn_author_selection(): void
    {
        ['user' => $user] = $this->compositionFixture();
        config(['compositions.emal' => null]);
        $this->assertNull($user->getCompositionAuthorEmployee(CompositionType::NEWBORN));
    }

    public function test_denied_focus_selection_does_not_call_esoz_or_change_the_patient(): void
    {
        ['legalEntity' => $entity, 'person' => $caregiver] = $this->compositionFixture(scopes: []);
        $child = $this->compositionPreperson();
        Http::fake();
        Livewire::test(CompositionTempDisabilityCreate::class, ['legalEntity' => $entity, 'preperson' => $child])
            ->set('form.category', 'CHILD_CARE')->call('selectFocus', $caregiver->id)
            ->assertSet('form.sectionFocusUuid', '')
            ->assertSee(__('compositions.errors.create_temp_disability_not_allowed'));
        Http::assertNothingSent();
    }

    public function test_focus_search_displays_external_subject_without_reassigning_storage(): void
    {
        ['legalEntity' => $entity, 'person' => $subject] = $this->compositionFixture();
        $card = $this->compositionPreperson();
        $uuid = (string) Str::uuid();
        $row = [
            'identifier' => $this->compositionReference('composition', $uuid)['identifier'],
            'subject' => $this->compositionReference('person', $subject->uuid)['identifier'],
            'section' => ['focus' => $this->compositionReference('preperson', $card->uuid)['identifier']],
            'status' => 'FINAL', 'title' => 'MV-FOCUS-01',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'date' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        $this->fakeEHealth(['*' => Http::response(['data' => [$row]])]);
        Livewire::test(PatientCompositions::class, ['legalEntity' => $entity, 'preperson' => $card])
            ->set('filterSectionFocusUuid', $card->uuid)->call('search')
            ->assertSet('focusSearchResults.0.identifier.value', $uuid)
            ->assertSee('MV-FOCUS-01')->assertSee($subject->uuid)
            ->call('viewFocusResult', $uuid)->assertSet('showDetailModal', true)
            ->assertSet('compositionDetail.subject.value', $subject->uuid);
        $this->assertDatabaseMissing('compositions', ['uuid' => $uuid]);
        $this->assertSame($subject->uuid, Repository::person()->getById($subject->id)->uuid);
    }
}
