<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionStatus;
use App\Livewire\Person\Records\PatientCompositions;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MergeRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class CompositionReadRelationsTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;
    public function test_details_preserve_false_flags_and_display_identifier_and_episode(): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        $native = $this->native($person, $employee->uuid, $legalEntity->uuid);
        $native['extension'] = [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => false]];
        $record = Repository::composition()->store($native, $person);
        $detail = $record->fresh()->toDetail();
        $this->assertEquals($native['episodeOfCare'], $detail['episodeOfCare']);
        $this->assertSame(false, $detail['extension'][0]['valueBoolean']);
        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('viewComposition', $record->uuid)->assertSet('showDetailModal', true)
            ->assertSee($record->uuid)->assertSee($native['episodeOfCare']['value'])
            ->assertSee(__('forms.no'))->assertSee(__('compositions.detail.flags.is_accident'));
    }

    public function test_identification_requires_a_signed_request_for_this_patient_and_preperson(): void
    {
        ['person' => $person] = $this->compositionFixture();
        $preperson = $this->compositionPreperson();
        $merge = MergeRequest::create([
            'uuid' => (string) Str::uuid(), 'master_person_id' => $person->id,
            'merge_person_id' => $preperson->id, 'status' => 'APPROVED',
        ]);
        $this->assertFalse(Repository::composition()->hasSignedIdentification($person, $preperson->id));
        $merge->update(['status' => 'SIGNED']);
        $this->assertTrue(Repository::composition()->hasSignedIdentification($person, $preperson->id));
        $foreign = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $this->assertFalse(Repository::composition()->hasSignedIdentification($foreign, $preperson->id));
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql' || !in_array(DB::connection()->getDatabaseName(), ['testing', 'tv39_1204'], true)) {
            throw new RuntimeException('Requires isolated PostgreSQL testing database.');
        }
    }

    public function test_registry_and_details_load_all_references_under_strict_lazy_loading(): void
    {
        ['person' => $person, 'employee' => $employee, 'legalEntity' => $legalEntity] = $this->compositionFixture();
        foreach (['First', 'Second'] as $title) {
            $native = $this->native($person, $employee->uuid, $legalEntity->uuid);
            $native['title'] = $title;
            Repository::composition()->store($native, $person);
        }
        $wasStrict = Model::preventsLazyLoading();
        Model::preventLazyLoading();
        try {
            $rows = Repository::composition()->paginateForPatient($person, [], 15);
            $this->assertCount(2, $rows);
            DB::enableQueryLog();
            DB::flushQueryLog();
            foreach ($rows as $row) {
                $this->assertTrue($row->hasReadContext);
                $this->assertSame($person->uuid, $row->toDetail()['subject']['value']);
                $this->assertSame($employee->uuid, $row->authorUuid);
            }
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->assertOk()->assertSee('First')->assertSee('Second');
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading($wasStrict);
        }
    }

    public function test_encounter_provider_keeps_patient_encounter_and_policy_boundaries(): void
    {
        $fixture = $this->compositionFixture(scopes: ['composition:search', 'composition:read', 'encounter:read']);
        $patient = $fixture['person'];
        $organization = Identifier::create(['value' => $fixture['legalEntity']->uuid]);
        $episode = Episode::create(['uuid' => (string) Str::uuid(), 'person_id' => $patient->id,
            'status' => 'active', 'name' => 'Test episode', 'managing_organization_id' => $organization->id]);
        $episodeReference = Identifier::create(['value' => $episode->uuid]);
        $class = Coding::create(['system' => 'eHealth/encounter_classes', 'code' => 'AMB']);
        $type = CodeableConcept::create(['text' => 'Test encounter type']);
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $patient->id,
            'status' => 'finished', 'episode_id' => $episodeReference->id, 'class_id' => $class->id, 'type_id' => $type->id]);
        $native = $this->native($patient, $fixture['employee']->uuid, $fixture['legalEntity']->uuid);
        $native['encounter']['value'] = $encounter->uuid;
        $expected = Repository::composition()->store($native, $patient);
        $otherEncounter = $native;
        $otherEncounter['identifier']['value'] = (string) Str::uuid();
        $otherEncounter['encounter']['value'] = (string) Str::uuid();
        Repository::composition()->store($otherEncounter, $patient);
        $foreign = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $otherPatient = $native;
        $otherPatient['identifier']['value'] = (string) Str::uuid();
        $otherPatient['subject']['value'] = $foreign->uuid;
        Repository::composition()->store($otherPatient, $foreign);
        $repository = Repository::composition();
        $this->assertSame([$expected->uuid], $repository->forEncounter($patient, $encounter, $fixture['user'])->pluck('uuid')->all());
        $this->assertCount(0, $repository->forEncounter($foreign, $encounter, $fixture['user']));
        $organization->update(['value' => (string) Str::uuid()]);
        $this->assertCount(0, $repository->forEncounter($patient, $encounter->fresh(), $fixture['user']));
        $organization->update(['value' => $fixture['legalEntity']->uuid]);
        $fixture['user']->revokePermissionTo('composition:read');
        $fixture['user']->roles->each->revokePermissionTo('composition:read');
        $this->assertCount(0, $repository->forEncounter($patient, $encounter->fresh(), $fixture['user']->fresh()));
        $this->assertSame(3, $expected->newQuery()->count());
        Http::assertNothingSent();
    }

    private function native(Person $patient, string $authorUuid, string $custodianUuid): array
    {
        $identifier = fn (string $type, string $uuid): array => $this->compositionReference($type, $uuid)['identifier'];

        return [
            'identifier' => ['value' => (string) Str::uuid()], 'status' => CompositionStatus::PRELIMINARY->value,
            'title' => 'Existing draft', 'date' => '2026-10-21T09:00:00Z',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'subject' => $identifier('person', $patient->uuid), 'author' => $identifier('employee', $authorUuid),
            'custodian' => $identifier('legal_entity', $custodianUuid),
            'encounter' => $identifier('encounter', (string) Str::uuid()),
            'episodeOfCare' => $identifier('episode_of_care', (string) Str::uuid()),
            'section' => ['focus' => $identifier('person', $patient->uuid)],
            'relatesTo' => ['code' => 'replaces', 'targetIdentifier' => $identifier('composition', (string) Str::uuid())],
            'event' => [['period' => ['start' => '2026-10-21T00:00:00Z', 'end' => '2026-10-21T23:59:59Z']]],
        ];
    }
}
