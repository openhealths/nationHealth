<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Livewire\EncounterPackage\EncounterReferralCreate;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class EncounterReferralSearchPersistenceTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use \Tests\Concerns\MocksBasicDictionaries;
    use UsesCompositionDictionaries;

    private Person $person;
    private LegalEntity $legalEntity;
    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        ['person' => $this->person, 'legalEntity' => $this->legalEntity, 'employee' => $employee] = $this->compositionFixture();
        $this->mockBasicDictionaries(['SERVICE_REQUEST_PRIORITY' => ['routine' => 'Планове'], 'SERVICE_UNIT' => ['PIECE' => 'шт.', 'SESSION' => 'сеанс'], 'SERVICE_CATEGORY' => ['diagnostic_procedure' => 'Діагностичні процедури']]);
        $episode = $this->episode($this->person);
        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'finished',
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create(['text' => 'Консультація'])->id,
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
        ]);
        Gate::before(fn (): bool => true);
    }

    public function test_medical_record_picker_is_scoped_to_the_patient_and_deduplicates_selections(): void
    {
        $own = $this->episode($this->person);
        $other = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $foreign = $this->episode($other);
        $component = $this->referralComponent()->call('loadReferralReferenceOptions');
        $options = $component->get('encounterReferralReferenceOptions');
        $this->assertArrayHasKey('episode:'.$own->uuid, $options);
        $this->assertArrayNotHasKey('episode:'.$foreign->uuid, $options);
        $component->call('addReferralReference', 'supporting_info', 'episode', $own->uuid)
            ->call('addReferralReference', 'supporting_info', 'episode', $own->uuid);
        $this->assertCount(1, $component->get('encounterReferralForm')['supporting_info']);
        $component->call('removeReferralReference', 'supporting_info', 'episode', $own->uuid)->assertSet('encounterReferralForm.supporting_info', []);
    }

    public function test_episode_cannot_be_added_as_a_clinical_reason(): void
    {
        $episode = $this->episode($this->person);
        $this->referralComponent()->call('addReferralReference', 'reason_reference', 'episode', $episode->uuid)->assertStatus(404);
    }

    public function test_changing_quantity_unit_updates_the_fhir_unit_label(): void
    {
        $this->referralComponent()->set('encounterReferralForm.quantity_code', 'SESSION')
            ->assertSet('encounterReferralForm.quantity_unit', 'сеанс');
    }

    public function test_draft_preserves_unit_and_supporting_episode_in_fhir_storage(): void
    {
        $episode = $this->episode($this->person);
        $component = $this->referralComponent()->set('encounterReferralForm.quantity_code', 'SESSION')
            ->call('addReferralReference', 'supporting_info', 'episode', $episode->uuid)->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $record = ServiceRequestRequest::wherePersonId($this->person->id)->firstOrFail();
        $resource = Repository::serviceRequest()->getResource($record);
        $this->assertSame('SESSION', data_get($resource, 'quantity.code'));
        $this->assertSame('сеанс', data_get($resource, 'quantity.unit'));
        $this->assertSame($episode->uuid, data_get($resource, 'supportingInfo.0.identifier.value'));
        $this->assertSame('draft', $record->status);
    }

    public function test_forged_reference_is_rejected_before_draft_creation(): void
    {
        $other = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $foreign = $this->episode($other);
        $this->referralComponent()->set('encounterReferralForm.supporting_info', [['type' => 'episode', 'uuid' => $foreign->uuid]])
            ->call('saveEncounterReferralDraft')->assertHasErrors(['encounterReferralForm.supporting_info']);
        $this->assertSame(0, ServiceRequestRequest::wherePersonId($this->person->id)->count());
    }

    public function test_program_field_uses_active_service_programs_and_their_backend_settings(): void
    {
        $withPlan = (string) Str::uuid();
        $withoutPlan = (string) Str::uuid();
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->once()->andReturn(collect([
            ['id' => $withoutPlan, 'name' => 'Послуги без плану', 'is_active' => true, 'type' => 'SERVICE',
                'medical_program_settings' => ['care_plan_required' => false]],
            ['id' => $withPlan, 'name' => 'Програма медичних гарантій', 'isActive' => true, 'type' => 'SERVICE',
                'medicalProgramSettings' => ['carePlanRequired' => true]],
            ['id' => (string) Str::uuid(), 'name' => 'Ліки', 'is_active' => true, 'type' => 'MEDICATION'],
            ['id' => (string) Str::uuid(), 'name' => 'Неактивна', 'is_active' => false, 'type' => 'SERVICE'],
        ]));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        $component = $this->referralComponent()->call('reloadProgramsForPersistenceTest');
        $programs = $component->get('encounterReferralPrograms');
        $this->assertSame([$withoutPlan, $withPlan], array_column($programs, 'id'));
        $this->assertFalse($programs[0]['medical_program_settings']['care_plan_required']);
        $this->assertTrue($programs[1]['medical_program_settings']['care_plan_required']);
        $component->assertSet('encounterReferralForm.program_id', $withPlan);
    }

    public function test_programs_can_load_through_the_authenticated_api_when_service_programs_are_not_cached(): void
    {
        $id = (string) Str::uuid();
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->once()->andReturn(collect([['id' => (string) Str::uuid(), 'name' => 'Ліки', 'is_active' => true, 'type' => 'MEDICATION']]));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-user-token');
        $responder = fn () => Http::response(['data' => [[
            'id' => $id, 'name' => 'Програма з API', 'is_active' => true, 'type' => 'SERVICE',
            'medical_program_settings' => ['care_plan_required' => true],
        ]]]);
        Http::fake($responder);
        $api = new \App\Classes\eHealth\Api\MedicalProgram(Http::getFacadeRoot());
        $api->stub([$responder])->preventStrayRequests();
        $this->instance(\App\Classes\eHealth\Api\MedicalProgram::class, $api);
        $component = $this->referralComponent()->call('reloadProgramsForPersistenceTest');
        $this->assertSame($id, $component->get('encounterReferralPrograms')[0]['id']);
        Http::assertSent(fn ($request) => $request['type'] === 'SERVICE' && (string) $request['is_active'] === '1');
    }

    public function test_service_programs_load_from_api_after_a_cache_read_failure(): void
    {
        $id = (string) Str::uuid();
        $basics = dictionary()->basics();
        $manager = \Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->once()->andThrow(new \RuntimeException('Unavailable cache'));
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $manager);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-user-token');
        $responder = fn () => Http::response(['data' => [[
            'id' => $id, 'name' => 'API program', 'is_active' => true, 'type' => 'SERVICE',
            'medical_program_settings' => ['care_plan_required' => false],
        ]]]);
        Http::fake($responder);
        $api = new \App\Classes\eHealth\Api\MedicalProgram(Http::getFacadeRoot());
        $api->stub([$responder])->preventStrayRequests();
        $this->instance(\App\Classes\eHealth\Api\MedicalProgram::class, $api);
        $component = $this->referralComponent()->call('reloadProgramsForPersistenceTest');
        $this->assertSame($id, $component->get('encounterReferralPrograms')[0]['id']);
        Http::assertSent(fn ($request) => $request['type'] === 'SERVICE' && (string) $request['is_active'] === '1');
    }

    public function test_record_picker_resolves_patient_episodes_and_uses_medical_record_dates(): void
    {
        config(['ehealth.condition.summary_conditions_allowed' => ['A98']]);
        $code = CodeableConcept::create(['text' => 'Профілактика']);
        $code->coding()->create(['code' => 'A98', 'system' => 'eHealth/ICPC2/condition_codes', 'display' => 'Профілактика']);
        $condition = \App\Models\MedicalEvents\Sql\Condition::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'primary_source' => true,
            'context_id' => Identifier::create(['value' => $this->encounter->uuid])->id, 'code_id' => $code->id,
            'clinical_status' => 'active', 'verification_status' => 'confirmed', 'onset_date' => '2026-05-08', 'ehealth_inserted_at' => '2026-05-08 10:00:00',
        ]);
        $component = $this->referralComponent()->call('loadReferralReferenceOptions')
            ->call('searchReferralReferenceOptions', $condition->uuid, 'condition');
        $record = $component->get('encounterReferralReferenceOptions')['condition:'.$condition->uuid];
        $this->assertStringNotContainsString($condition->uuid, $record['label']);
        $this->assertSame($this->encounter->episode->value, $record['episode_uuid']);
        $this->assertSame('08.05.2026', $record['date']);
        $component->call('searchReferralReferenceOptions', 'Профілактика', 'condition');
        $this->assertArrayHasKey('condition:'.$condition->uuid, $component->get('encounterReferralReferenceOptions'));
        $component->call('addReferralReference', 'reason_reference', 'condition', $condition->uuid);
        $this->assertSame($condition->uuid, $component->get('encounterReferralForm')['reason_reference'][0]['uuid']);
    }

    public function test_available_observations_and_reports_do_not_need_summary_code_configuration(): void
    {
        config(['ehealth.observation.summary_observations_allowed' => [], 'ehealth.diagnostic_report.summary_diagnostic_reports_allowed' => []]);
        $code = CodeableConcept::create(['text' => 'Available observation']);
        $code->coding()->create(['code' => 'test', 'system' => 'test']);
        $context = Identifier::create(['value' => $this->encounter->uuid]);
        $observations = [];
        for ($index = 0; $index < 23; $index++) {
            $observations[] = \App\Models\MedicalEvents\Sql\Observation::create([
                'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'valid',
                'code_id' => $code->id, 'context_id' => $context->id, 'primary_source' => true,
                'issued' => '2026-11-01T09:00:00Z', 'effective_date_time' => '2026-11-01T09:00:00Z',
            ]);
        }
        $report = \App\Models\MedicalEvents\Sql\DiagnosticReport::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'final',
            'code_id' => Identifier::create(['value' => (string) Str::uuid(), 'display_value' => 'Available diagnostic report'])->id, 'encounter_id' => $context->id, 'primary_source' => true, 'recorded_by_id' => $this->encounter->performer_id,
            'issued' => '2026-11-01T09:00:00Z', 'effective_date_time' => '2026-11-01T09:00:00Z',
        ]);
        $component = $this->referralComponent()->call('loadReferralReferenceOptions', 'supporting_info')
            ->call('searchReferralReferenceOptions', '', 'observation')->assertSet('encounterReferralReferenceHasMore', true);
        $this->assertCount(20, $component->get('encounterReferralReferenceOptions'));
        // Access checks query the selected UUID even when it is absent from the current picker page.
        $loadedUuids = array_column($component->get('encounterReferralReferenceOptions'), 'uuid');
        $outsidePage = collect($observations)->first(fn ($observation): bool => !in_array($observation->uuid, $loadedUuids, true));
        $this->assertNotNull($outsidePage);
        $this->assertNotContains($outsidePage->uuid, $loadedUuids);
        $component->call('addReferralReference', 'supporting_info', 'observation', $outsidePage->uuid);
        $this->assertSame($outsidePage->uuid, $component->get('encounterReferralForm')['supporting_info'][0]['uuid']);
        $component->call('loadMoreReferralReferenceOptions')->assertSet('encounterReferralReferenceHasMore', false);
        $this->assertCount(23, $component->get('encounterReferralReferenceOptions'));
        $component->call('searchReferralReferenceOptions', 'Available diagnostic report', 'diagnostic_report');
        $this->assertArrayHasKey('diagnostic_report:'.$report->uuid, $component->get('encounterReferralReferenceOptions'));
        $component->call('addReferralReference', 'supporting_info', 'diagnostic_report', $report->uuid)
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $this->assertCount(2, Repository::serviceRequest()->getResource(ServiceRequestRequest::wherePersonId($this->person->id)->sole())['supportingInfo']);
    }

    public function test_reason_filter_can_load_older_episodes_independently_of_record_types(): void
    {
        for ($index = 0; $index < 23; $index++) {
            $this->episode($this->person);
        }
        $component = $this->referralComponent()->call('loadReferralReferenceOptions', 'reason_reference')
            ->assertSet('encounterReferralEpisodesHasMore', true);
        $this->assertCount(20, $component->get('encounterReferralEpisodes'));
        $loaded = array_column($component->get('encounterReferralEpisodes'), 'uuid');
        $oldest = Episode::forPatient($this->person)->orderBy('id')->first();
        $this->assertNotContains($oldest->uuid, $loaded);
        $component->call('loadMoreReferralEpisodeOptions')->assertSet('encounterReferralEpisodesHasMore', false);
        $this->assertCount(24, $component->get('encounterReferralEpisodes'));
        $this->assertContains($oldest->uuid, array_column($component->get('encounterReferralEpisodes'), 'uuid'));
        $component->call('searchReferralReferenceOptions', '', 'condition', $oldest->uuid)
            ->assertSet('encounterReferralReferenceEpisode', $oldest->uuid);
    }

    public function test_quantity_unit_is_derived_from_the_validated_code(): void
    {
        $this->referralComponent()->set('encounterReferralForm.quantity_unit', 'forged-unit')
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $resource = Repository::serviceRequest()->getResource(ServiceRequestRequest::wherePersonId($this->person->id)->sole());
        $this->assertSame('PIECE', data_get($resource, 'quantity.code'));
        $this->assertSame(dictionary()->basics()->byName('SERVICE_UNIT')->asCodeDescription()->all()['PIECE'], data_get($resource, 'quantity.unit'));
    }

    public function test_new_reference_uses_the_checked_pair_even_with_a_forged_identifier(): void
    {
        $episode = $this->episode($this->person);
        $foreign = (string) Str::uuid();
        $this->referralComponent()->set('encounterReferralForm.supporting_info', [[
            'type' => 'episode', 'uuid' => $episode->uuid,
            'identifier' => ['value' => $foreign, 'type' => ['coding' => [['code' => 'condition', 'system' => 'eHealth/resources']]]],
        ]])->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $record = ServiceRequestRequest::wherePersonId($this->person->id)->sole();
        $reference = Repository::serviceRequest()->getResource($record)['supportingInfo'][0];
        $this->assertSame($episode->uuid, data_get($reference, 'identifier.value'));
        $this->assertSame('episode', data_get($reference, 'identifier.type.coding.0.code'));
        $this->assertStringNotContainsString($foreign, json_encode($reference));
        $this->assertArrayNotHasKey('uuid', $reference);
    }

    public function test_legacy_reference_projection_survives_validation_and_cannot_be_replaced(): void
    {
        $component = $this->referralComponent()->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $legacy = ['displayValue' => 'Existing medical record description'];
        // This is the form projection used for display-only references by the DTO in #1230.
        $component->call('loadLegacyReferencesForTest', [$legacy])
            ->set('encounterReferralForm.note', 'Edited note')->call('validateReferencesForTest')->assertHasNoErrors();
        $this->assertSame([$legacy], $component->get('encounterReferralForm')['supporting_info']);
        $this->assertSame('Edited note', $component->get('encounterReferralForm')['note']);
        $component->set('encounterReferralForm.supporting_info', [['displayValue' => 'Forged description']])
            ->call('saveEncounterReferralDraft')->assertHasErrors(['encounterReferralForm.supporting_info']);
    }

    public function test_text_only_references_are_visible_and_removed_individually(): void
    {
        $first = ['displayValue' => 'First saved reference'];
        $second = ['displayValue' => 'Second saved reference'];
        $component = $this->referralComponent()->call('loadLegacyReferencesForTest', [$first, $second])
            ->assertSet('encounterReferralForm.supporting_info', [$first, $second]);
        $component->call('removeReferralReference', 'supporting_info', '', '', md5(json_encode($second)))
            ->assertSet('encounterReferralForm.supporting_info', [$first]);
        $component->call('validateReferencesForTest')->assertHasNoErrors();
    }


    public function test_empty_quantity_code_is_stored_with_the_piece_code_and_dictionary_label(): void
    {
        $this->referralComponent()->set('encounterReferralForm.quantity_code', '')
            ->set('encounterReferralForm.quantity_unit', 'untrusted')
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $resource = Repository::serviceRequest()->getResource(ServiceRequestRequest::wherePersonId($this->person->id)->sole());
        $this->assertSame('PIECE', data_get($resource, 'quantity.code'));
        $this->assertSame('шт.', data_get($resource, 'quantity.unit'));
    }

    private function referralComponent()
    {
        Livewire::component('referral-search-persistence-fixture', ReferralSearchPersistenceFixture::class);

        return Livewire::test('referral-search-persistence-fixture', ['personId' => $this->person->id, 'encounterId' => $this->encounter->id, 'legalEntityId' => $this->legalEntity->id]);
    }

    private function episode(Person $person): Episode
    {
        return Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'name' => 'Епізод для направлення',
            'managing_organization_id' => Identifier::create(['value' => $this->legalEntity->uuid])->id]);
    }
}

class ReferralSearchPersistenceFixture extends EncounterReferralCreate
{
    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?Encounter $encounter = null, ?int $referral = null): void
    {
        $this->uuid = Person::findOrFail($this->personId)->uuid;
        $this->encounterUuid = Encounter::findOrFail($this->encounterId)->uuid;
        $this->patientFullName = 'Тестовий пацієнт';
        $start = now()->addMinutes(30)->startOfMinute();
        $this->encounterReferralPrograms = [['id' => (string) Str::uuid(), 'name' => 'Програма медичних гарантій', 'medical_program_settings' => ['care_plan_required' => false]]];
        $this->encounterReferralForm = [
            'service_id' => (string) Str::uuid(), 'category' => 'diagnostic_procedure', 'priority' => 'routine',
            'quantity' => 1, 'quantity_code' => 'PIECE', 'quantity_unit' => 'шт.', 'quantity_system' => 'SERVICE_UNIT',
            'started_at' => $start->format('d.m.Y'), 'ended_at' => $start->copy()->addMonths(3)->format('d.m.Y'),
            'start_time' => $start->format('H:i'), 'end_time' => $start->format('H:i'),
            'program_id' => $this->encounterReferralPrograms[0]['id'], 'note' => '', 'patient_instruction' => '', 'inform_with' => '',
            'reason_reference' => [], 'supporting_info' => [], 'performer' => '', 'location_reference' => '', 'performer_type' => '',
        ];
    }
    public function validateReferencesForTest(): void
    {
        $this->validateEncounterReferralReferences();
    }

    public function loadLegacyReferencesForTest(array $references): void
    {
        $this->encounterReferralOriginalReferences = ['supporting_info' => $references];
        $this->encounterReferralForm['supporting_info'] = $references;
    }

    public function reloadProgramsForPersistenceTest(): void
    {
        $this->loadEncounterReferralPrograms();
        $this->encounterReferralForm['program_id'] = $this->resolveDefaultEncounterReferralProgramId();
    }
}
