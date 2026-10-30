<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Repositories\CarePlanRepository;
use App\Repositories\MedicalEvents\EncounterRepository;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EncounterPrimaryDiagnosisPersistenceTest extends TestCase
{
    use DatabaseTransactions;
    public function test_terms_of_service_follow_the_selected_encounter_and_patient(): void
    {
        $encounter = $this->encounter();
        $repository = app(CarePlanRepository::class);
        $this->assertSame('OUTPATIENT', $repository->encounterTermsOfService($encounter->uuid, $encounter->person_id));
        $inpatient = Repository::coding()->store(['system' => 'eHealth/encounter_classes', 'code' => 'INPATIENT']);
        $encounter->update(['class_id' => $inpatient->id]);
        $this->assertSame('INPATIENT', $repository->encounterTermsOfService($encounter->uuid, $encounter->person_id));
        $foreign = $this->encounter();
        $this->assertSame('', $repository->encounterTermsOfService($encounter->uuid, $foreign->person_id));
    }

    public function test_rehabilitation_grounds_require_a_valid_icf_observation_from_the_same_episode(): void
    {
        $encounter = $this->encounter();
        $other = $this->encounter();
        $plan = new \App\Models\CarePlan(['person_id' => $encounter->person_id, 'encounter_id' => $encounter->id]);
        $context = Identifier::create(['value' => $encounter->uuid]);
        $code = Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICF/classifiers', 'code' => 'b280']]]);
        $observation = \App\Models\MedicalEvents\Sql\Observation::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $encounter->person_id,
            'context_id' => $context->id, 'code_id' => $code->id, 'status' => 'valid',
            'issued' => now(), 'primary_source' => true,
        ]);
        $category = Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICF/observation_categories', 'code' => 'functions']]]);
        $observation->categories()->attach($category->id);
        $references = [['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'observation']]], 'value' => $observation->uuid]]];
        $repository = app(CarePlanRepository::class);
        $this->assertTrue($repository->hasRehabilitationObservation($plan, $references));
        $this->assertFalse($repository->hasRehabilitationObservation($plan, ['Condition/'.$observation->uuid]));
        $this->assertFalse($repository->hasRehabilitationObservation($plan, ['Observation/'.Str::uuid()]));
        $observation->update(['person_id' => $other->person_id]);
        $this->assertFalse($repository->hasRehabilitationObservation($plan, $references));
        $observation->update(['person_id' => $encounter->person_id, 'status' => 'entered_in_error']);
        $this->assertFalse($repository->hasRehabilitationObservation($plan, $references));
        $observation->update(['status' => 'valid']);
        $context->update(['value' => $other->uuid]);
        $this->assertFalse($repository->hasRehabilitationObservation($plan, $references));
        $context->update(['value' => $encounter->uuid]);
        $observation->categories()->detach();
        $this->assertFalse($repository->hasRehabilitationObservation($plan, $references));
    }

    private function encounter(): Encounter
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE',
            'patient_signed' => true, 'process_disclosure_data_consent' => true,
        ]);
        $episode = Identifier::create(['value' => (string) Str::uuid()]);
        $class = Repository::coding()->store(['system' => 'eHealth/encounter_classes', 'code' => 'AMB']);
        $type = Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'TEST']]]);

        return Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'episode_id' => $episode->id, 'class_id' => $class->id, 'type_id' => $type->id,
            'ehealth_inserted_at' => now(),
        ]);
    }

    private function diagnosis(Encounter $encounter, string $roleCode, string $code, int $rank): Condition
    {
        $identifier = Identifier::create(['value' => (string) Str::uuid()]);
        $concept = Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => $code]]]);
        $condition = Condition::create([
            'uuid' => $identifier->value, 'person_id' => $encounter->person_id,
            'primary_source' => true, 'clinical_status' => ConditionClinicalStatus::ACTIVE,
            'verification_status' => ConditionVerificationStatus::CONFIRMED,
            'code_id' => $concept->id, 'context_id' => $encounter->episode_id, 'onset_date' => now(),
        ]);
        $role = Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/diagnosis_roles', 'code' => $roleCode]]]);
        EncounterDiagnose::create([
            'encounter_id' => $encounter->id, 'condition_id' => $identifier->id,
            'role_id' => $role->id, 'rank' => $rank,
        ]);

        return $condition;
    }

    public function test_encounter_choices_exclude_known_foreign_legal_entity_episodes(): void
    {
        $organizationUuid = (string) Str::uuid();
        $this->instance('legalEntity', new \App\Models\LegalEntity(['uuid' => $organizationUuid]));
        $encounter = $this->encounter();
        $foreign = $this->encounter();
        $foreign->update(['person_id' => $encounter->person_id]);
        $unknown = $this->encounter();
        $unknown->update(['person_id' => $encounter->person_id]);
        foreach ([[$encounter, $organizationUuid], [$foreign, (string) Str::uuid()]] as [$record, $ownerUuid]) {
            $owner = Identifier::create(['value' => $ownerUuid]);
            Episode::create([
                'uuid' => $record->episode->value, 'person_id' => $encounter->person_id,
                'status' => 'active', 'name' => 'Synthetic episode', 'managing_organization_id' => $owner->id,
            ]);
        }

        $choices = app(CarePlanRepository::class)->encounterChoices((int) $encounter->person_id);

        $this->assertCount(1, $choices);
        $this->assertSame($encounter->uuid, $choices[0]['uuid']);
    }

    public function test_encounter_choices_read_selected_timestamp_under_strict_attributes(): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        $encounter = $this->encounter();
        $encounter->update(['ehealth_inserted_at' => '2026-10-22T22:30:00Z']);
        $undated = $this->encounter();
        $undated->update(['person_id' => $encounter->person_id, 'ehealth_inserted_at' => null]);
        $unfinished = $this->encounter();
        $unfinished->update(['person_id' => $encounter->person_id, 'status' => 'draft']);
        $otherPatient = $this->encounter();
        $wasPreventing = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        try {
            $choices = app(CarePlanRepository::class)->encounterChoices((int) $encounter->person_id);
            $labels = array_column($choices, 'label', 'uuid');

            $this->assertCount(2, $choices);
            $this->assertSame(__('Взаємодія #').$encounter->id.' (23.10.2026)', $labels[$encounter->uuid]);
            $this->assertSame(__('Взаємодія #').$undated->id.' (-)', $labels[$undated->uuid]);
            $this->assertArrayNotHasKey($unfinished->uuid, $labels);
            $this->assertArrayNotHasKey($otherPatient->uuid, $labels);
        } finally {
            Model::preventAccessingMissingAttributes($wasPreventing);
        }
    }

    public function test_encounter_choices_show_clinical_date_and_patient_episode_without_lazy_loading(): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        $encounter = $this->encounter();
        $encounter->update(['ehealth_inserted_at' => null]);
        $encounter->period()->create(['start' => '2026-10-22T22:30:00Z']);
        Episode::create([
            'uuid' => $encounter->episode->value, 'person_id' => $encounter->person_id,
            'status' => 'active', 'name' => 'Контроль лікування',
        ]);

        $otherPatient = $this->encounter();
        $otherEpisode = $this->encounter();
        $otherEpisode->update(['person_id' => $encounter->person_id, 'ehealth_inserted_at' => '2026-10-25 12:00:00']);
        $otherEpisode->period()->create(['start' => '2026-10-21 12:00:00']);
        Episode::create([
            'uuid' => $otherEpisode->episode->value, 'person_id' => $otherPatient->person_id,
            'status' => 'active', 'name' => 'Епізод іншого пацієнта',
        ]);

        $invalidEpisode = $this->encounter();
        $invalidEpisode->update(['person_id' => $encounter->person_id, 'ehealth_inserted_at' => null]);
        $invalidEpisode->episode()->update(['value' => 'not-an-episode-uuid']);
        $wasPreventingMissingAttributes = Model::preventsAccessingMissingAttributes();
        $wasPreventingLazyLoading = Model::preventsLazyLoading();
        Model::preventAccessingMissingAttributes();
        Model::preventLazyLoading();
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $choices = app(CarePlanRepository::class)->encounterChoices((int) $encounter->person_id);
            $queryCount = count(DB::getQueryLog());
            $labels = array_column($choices, 'label', 'uuid');

            $this->assertCount(3, $choices);
            $this->assertSame(__('Взаємодія #').$encounter->id.' (23.10.2026) — Контроль лікування', $labels[$encounter->uuid]);
            $this->assertSame(__('Взаємодія #').$otherEpisode->id.' (21.10.2026)', $labels[$otherEpisode->uuid]);
            $this->assertSame(__('Взаємодія #').$invalidEpisode->id.' (-)', $labels[$invalidEpisode->uuid]);
            $this->assertArrayNotHasKey($otherPatient->uuid, $labels);
            $this->assertLessThanOrEqual(4, $queryCount);
        } finally {
            DB::disableQueryLog();
            Model::preventAccessingMissingAttributes($wasPreventingMissingAttributes);
            Model::preventLazyLoading($wasPreventingLazyLoading);
        }
    }

    public function test_varchar_identifier_resolves_primary_uuid_with_codings_under_strict_loading(): void
    {
        $encounter = $this->encounter();
        $this->diagnosis($encounter, 'secondary', 'E10', 1);
        $primary = $this->diagnosis($encounter, 'primary', 'E11', 2);
        $wasPreventing = Model::preventsLazyLoading();
        Model::preventLazyLoading();
        try {
            $conditions = app(EncounterRepository::class)->primaryDiagnosisConditions($encounter->fresh());
            $this->assertSame([$primary->uuid], $conditions->pluck('uuid')->all());
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertSame('E11', $conditions->first()->code->coding->first()->code);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading($wasPreventing);
        }
    }

    public function test_primary_condition_of_another_patient_is_not_resolved(): void
    {
        $encounter = $this->encounter();
        $otherEncounter = $this->encounter();
        $primary = $this->diagnosis($encounter, 'primary', 'E11', 1);
        $primary->update(['person_id' => $otherEncounter->person_id]);

        $this->assertTrue(app(EncounterRepository::class)->primaryDiagnosisConditions($encounter->fresh())->isEmpty());
    }

    public function test_non_uuid_identifier_does_not_abort_the_postgresql_transaction(): void
    {
        $encounter = $this->encounter();
        $this->diagnosis($encounter, 'primary', 'E11', 1);
        $diagnosis = $encounter->diagnoses()->firstOrFail();
        $diagnosis->condition()->update(['value' => 'not-a-condition-uuid']);

        $this->assertTrue(app(EncounterRepository::class)->primaryDiagnosisConditions($encounter->fresh())->isEmpty());
        $this->assertTrue(Encounter::whereUuid($encounter->uuid)->exists());
    }
}
