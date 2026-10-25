<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\DeviceDispenseRepository;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\EncounterPackageHarness as EncounterPackageBuilder;
use Tests\Support\EncounterPackageHarness as EncounterPackageLoader;
use Tests\TestCase;

class DeviceDispenseRepositoryMappingTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('patientAndSelectionTypes')]
    public function test_actual_package_store_sync_and_form_load_with_supporting_document_lookup(string $patientType, string $selection): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $patient = $patientType::create([
            'uuid' => (string) Str::uuid(), 'gender' => 'MALE', 'birth_date' => '1990-01-01',
            ...($patientType === Person::class ? ['patient_signed' => true, 'process_disclosure_data_consent' => true] : []),
        ]);
        $ownerColumn = $patientType === Person::class ? 'person_id' : 'preperson_id';
        $uuids = array_combine(['encounter', 'visit', 'episode', 'employee'], array_map(
            static fn (): string => (string) Str::uuid(),
            range(1, 4),
        ));
        $supportUuid = (string) Str::uuid();
        Condition::create([
            'uuid' => $supportUuid, $ownerColumn => $patient->id, 'primary_source' => true,
            'context_id' => Repository::identifier()->store($uuids['encounter'])->id,
            'code_id' => Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICPC2/condition_codes', 'code' => 'D02']]])->id,
            'clinical_status' => 'active', 'verification_status' => 'confirmed', 'onset_date' => '2026-10-01T09:00:00Z',
            'ehealth_inserted_at' => '2026-10-01T09:00:00Z',
        ]);
        $row = [
            'uuid' => (string) Str::uuid(), 'quantity' => 0, 'quantityCode' => 'piece',
            'deviceSelectionType' => $selection, 'deviceDefinitionId' => (string) Str::uuid(), 'deviceCode' => '30221',
            'performerId' => $uuids['employee'], 'locationId' => (string) Str::uuid(),
            'basedOnId' => (string) Str::uuid(), 'partOfId' => (string) Str::uuid(),
            'whenHandedOverDate' => '05.10.2026', 'whenHandedOverTime' => '10:15', 'note' => 'Виданий виріб',
            'supportingInfo' => [42 => ['uuid' => $supportUuid, 'type' => 'condition']],
        ];
        $package = app(EncounterPackageBuilder::class)->toFhir([
            'deviceDispenses' => [42 => $row],
            'encounter' => [
                'periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00',
                'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => $uuids['employee'],
                'referralType' => '', 'diagnoses' => [],
            ],
        ], $uuids);
        $repository = app(DeviceDispenseRepository::class);
        $repository->store($package['deviceDispenses'], $patient);

        $stored = DeviceDispense::where('uuid', $row['uuid'])->firstOrFail();
        $this->assertSame($patient->id, $stored->getRawOriginal($ownerColumn));
        $this->assertNull($stored->getRawOriginal($patientType === Person::class ? 'preperson_id' : 'person_id'));
        $this->assertSame($uuids['encounter'], $stored->encounter->value);
        $this->assertSame($row['performerId'], $stored->performer->value);
        $this->assertSame($row['locationId'], $stored->location->value);
        $this->assertSame($row['basedOnId'], $stored->basedOn->value);
        $this->assertSame($row['partOfId'], $stored->partOf->value);
        $form = $this->load($uuids['encounter']);
        $this->assertSame(0, $form['quantity']);
        $this->assertSame('piece', $form['quantityCode']);
        $this->assertSame($selection, $form['deviceSelectionType']);
        $this->assertSame($selection === 'model' ? $row['deviceDefinitionId'] : '', $form['deviceDefinitionId']);
        $this->assertSame($selection === 'type' ? '30221' : '', $form['deviceCode']);
        $this->assertSame('05.10.2026', $form['whenHandedOverDate']);
        $this->assertSame('10:15', $form['whenHandedOverTime']);
        $this->assertSame('Виданий виріб', $form['note']);
        $this->assertSame([['uuid' => $supportUuid, 'type' => 'condition', 'ehealthInsertedAt' => '01.10.2026 12:00', 'code' => 'D02']], $form['supportingInfo']);

        $remote = Arr::toSnakeCase($package['deviceDispenses'][0]);
        $remote['uuid'] = $row['uuid'];
        $remote['status'] = 'in_progress';
        $remote['details'][0]['quantity']['value'] = 5.9;
        $remote['context_episode_id'] = (string) Str::uuid();
        $remote['origin_episode_id'] = (string) Str::uuid();
        $remote['performer']['display_value'] = 'Лікар';
        $remote['performer_legal_entity'] = [
            'identifier' => [
                'value' => (string) Str::uuid(),
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']], 'text' => ''],
            ],
            'display_value' => 'Заклад',
        ];
        $remote['ehealth_inserted_at'] = '2026-10-05T07:00:00Z';
        $repository->sync($patient, [$remote]);
        $form = $this->load($uuids['encounter']);
        $this->assertSame('in_progress', $form['status']);
        $this->assertSame(5, $form['quantity']);
        $this->assertSame($remote['context_episode_id'], $form['contextEpisodeId']);
        $this->assertSame($remote['origin_episode_id'], $form['originEpisodeId']);
        $this->assertSame('Лікар', $form['performerName']);
        $this->assertSame('Заклад', $form['legalEntityName']);
        $this->assertSame('05.10.2026', $form['createdDate']);
        $this->assertSame($supportUuid, $form['supportingInfo'][0]['uuid']);
        $this->assertSame('D02', $form['supportingInfo'][0]['code']);
        $this->assertSame(1, DeviceDispense::where('uuid', $row['uuid'])->count());
        Http::assertNothingSent();
    }

    private function load(string $encounter): array
    {
        $rows = new ReflectionMethod(EncounterPackageLoader::class, 'loadDeviceDispenses')
            ->invoke(app(EncounterPackageLoader::class), $encounter);
        $this->assertCount(1, $rows);

        return array_values($rows)[0];
    }

    public static function patientAndSelectionTypes(): iterable
    {
        foreach ([Person::class, Preperson::class] as $patientType) {
            foreach (['model', 'type'] as $selection) {
                yield $patientType.' / '.$selection => [$patientType, $selection];
            }
        }
    }
}
