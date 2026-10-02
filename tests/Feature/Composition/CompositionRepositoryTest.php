<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\CompositionRepository;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompositionRepositoryTest extends TestCase
{
    use RefreshCompositionDatabase;

    private const string COMPOSITION_ID = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';

    private const string ENCOUNTER_ID = 'e39ee5ae-2644-4f04-8e64-bb359866e907';

    private const string EPISODE_ID = 'c7c41d7e-f0e5-4118-b5be-fedfb5a1e8ed';

    private const string PATIENT_ID = '7075e0e2-6b57-47fd-aff7-324806efa7e5';

    public function test_store_local_mirrors_the_conclusion_with_its_real_identifier(): void
    {
        $person = $this->person();

        $composition = $this->repository()->store(
            $this->details(),
            $person,
            self::EPISODE_ID
        );

        $this->assertNotNull($composition);
        $this->assertSame(self::COMPOSITION_ID, $composition->uuid);
        $this->assertSame($person->id, $composition->personId);
        $this->assertSame(CompositionType::TEMP_DISABILITY, $composition->type);
        $this->assertSame(CompositionStatus::PRELIMINARY, $composition->status);
        $this->assertSame(self::ENCOUNTER_ID, $composition->encounterUuid);
        $this->assertSame(self::EPISODE_ID, $composition->episodeOfCareUuid);
        $this->assertSame('ТН-0001', $composition->title);
    }

    public function test_store_local_flattens_extensions_into_their_columns(): void
    {
        $composition = $this->repository()->store($this->details([
            'extension' => [
                ['valueCode' => 'INFORM_WITH', 'valueUuid' => 'aaaaaaaa-0000-4000-8000-000000000001'],
                ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true],
                ['valueCode' => 'TREATMENT_VIOLATION', 'valueString' => 'reject_recommendation'],
                ['valueCode' => 'TREATMENT_VIOLATION_DATE', 'valueDate' => '2026-08-15'],
            ],
        ]), $this->person());

        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $composition->informWithUuid);
        $this->assertTrue($composition->isAccident);
        $this->assertSame('reject_recommendation', $composition->treatmentViolation);
        $this->assertSame('2026-08-15', $composition->treatmentViolationDate->format('Y-m-d'));
    }

    /**
     * getComposition carries far more than searchComposition does, so a subsequent narrow
     * refresh must not blank out what was captured here.
     */
    public function test_a_later_narrower_refresh_does_not_erase_stored_details(): void
    {
        $person = $this->person();
        $repository = $this->repository();

        $repository->store($this->details(), $person, self::EPISODE_ID);
        $repository->store([
            'identifier' => ['value' => self::COMPOSITION_ID],
            'status' => 'FINAL',
        ], $person);

        $composition = Composition::whereUuid(self::COMPOSITION_ID)->first();

        $this->assertSame(CompositionStatus::FINAL, $composition->status);
        $this->assertSame('ТН-0001', $composition->title, 'Title must survive a narrower refresh.');
        $this->assertSame(self::ENCOUNTER_ID, $composition->encounterUuid);
        $this->assertSame(self::PATIENT_ID, data_get($composition->toDetail(), 'section.focus.value'));
        $this->assertSame($this->details()['event'], $composition->toDetail()['event']);
        $this->assertSame('FINAL', $composition->toDetail()['status']);
    }

    public function test_repeated_synchronization_reuses_fhir_records(): void
    {
        $person = $this->person();
        $repository = $this->repository();
        $composition = $repository->store($this->details(), $person, self::EPISODE_ID);
        $identifiers = \App\Models\MedicalEvents\Sql\Identifier::count();
        $concepts = \App\Models\MedicalEvents\Sql\CodeableConcept::count();

        $updated = $repository->store($this->details(), $person, self::EPISODE_ID);

        $this->assertSame($composition->id, $updated->id);
        $this->assertSame($identifiers, \App\Models\MedicalEvents\Sql\Identifier::count());
        $this->assertSame($concepts, \App\Models\MedicalEvents\Sql\CodeableConcept::count());
        $this->assertSame(1, $updated->eventPeriod()->count());
    }

    public function test_empty_extension_list_replaces_previous_flags(): void
    {
        $person = $this->person();
        $repository = $this->repository();
        $repository->store($this->details(['extension' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true]]]), $person);
        $updated = $repository->store($this->details(['extension' => []]), $person);

        $this->assertSame([], $updated->toDetail()['extension']);
        $this->assertFalse($updated->isAccident);
    }

    public function test_normalized_integrations_replace_the_previous_snapshot_atomically(): void
    {
        $composition = $this->repository()->store($this->details(), $this->person());
        $this->repository()->storeIntegration($composition, [
            ['component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'integrationStatus' => 'ERROR'],
            ['component' => 'DRACS', 'type' => 'CHECK_DRACS', 'taskStatus' => 'PENDING'],
        ]);
        $this->assertSame('ERROR', $composition->erlnStatus);
        $this->repository()->storeIntegration($composition, [
            ['component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'integrationStatus' => 'SUCCESS',
                'details' => ['SL_NUM' => '1234'], 'updatedAt' => '2026-10-01T10:00:00Z'],
        ]);
        $this->assertCount(1, $composition->integrationDetails());
        $this->assertSame('SUCCESS', $composition->erlnStatus);
        $this->assertSame('1234', $composition->erlnRecordNumber);
        $this->repository()->storeIntegration($composition, []);
        $this->assertNull($composition->erlnStatus);
    }

    public function test_failed_storage_rolls_back_fhir_records(): void
    {
        $person = $this->person();
        $before = \App\Models\MedicalEvents\Sql\Identifier::count();

        try {
            $this->repository()->store($this->details(['date' => 'not-a-date']), $person);
            $this->fail('Invalid date must prevent persistence.');
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            $this->assertSame($before, \App\Models\MedicalEvents\Sql\Identifier::count());
            $this->assertSame(0, Composition::count());
        }
    }

    public function test_a_response_without_an_identifier_is_ignored(): void
    {
        $this->assertNull($this->repository()->store(['status' => 'FINAL'], $this->person()));
        $this->assertSame(0, Composition::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'identifier' => ['value' => self::COMPOSITION_ID],
            'status' => 'PRELIMINARY',
            'title' => 'ТН-0001',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'date' => '2026-08-13T10:00:00Z',
            'encounter' => ['value' => self::ENCOUNTER_ID],
            'author' => ['value' => '43cc2161-1c2b-481b-a618-77e35817f850'],
            'custodian' => ['value' => 'bbbbbbbb-0000-4000-8000-000000000001'],
            'subject' => ['value' => self::PATIENT_ID],
            'section' => ['focus' => ['value' => self::PATIENT_ID]],
            'event' => [['period' => ['start' => '2026-08-01T00:00:01Z', 'end' => '2026-08-10T20:59:59Z']]],
        ], $overrides);
    }

    private function person(): Person
    {
        return Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Пацієнт',
            'last_name' => 'Якийсь',
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
        ]);
    }

    private function repository(): CompositionRepository
    {
        return app(CompositionRepository::class);
    }

}
