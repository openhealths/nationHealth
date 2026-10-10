<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\SpecimenRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\EncounterPackageHarness as EncounterPackageBuilder;
use Tests\Support\EncounterPackageHarness as EncounterPackageLoader;
use Tests\TestCase;

class SpecimenRepositoryMappingTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('patientsAndTiming')]
    public function test_package_store_sync_and_hydration_preserve_nested_relations(string $patientType, string $timing): void
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
        $legalEntityUuid = (string) Str::uuid();
        $this->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => $legalEntityUuid]));
        $uuids = array_combine(['encounter', 'visit', 'episode', 'employee'], array_map(
            static fn (): string => (string) Str::uuid(),
            range(1, 4),
        ));
        $contracts = require dirname(__DIR__, 3).'/Fixtures/Mapping/specimen-inputs.php';
        $row = $contracts['full sparse containers']['outbound'];
        $row['uuid'] = (string) Str::uuid();
        $row['collectorId'] = $patient->uuid;
        $row['collectorType'] = 'patient';
        $row['procedureId'] = (string) Str::uuid();
        $row['parentIds'] = [9 => (string) Str::uuid(), 42 => (string) Str::uuid()];
        if ($timing === 'period') {
            $row = array_replace($row, [
                'collectedType' => 'period', 'collectedPeriodRange' => '04.10.2026 — 05.10.2026',
                'collectedPeriodStartTime' => '09:00', 'collectedPeriodEndTime' => '10:00',
            ]);
        }
        $package = app(EncounterPackageBuilder::class)->toFhir([
            'specimens' => [42 => $row],
            'encounter' => [
                'periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00',
                'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => $uuids['employee'],
                'referralType' => '', 'diagnoses' => [],
            ],
        ], $uuids);
        $repository = app(SpecimenRepository::class);
        $repository->store($package['specimens'], $patient);
        $stored = Specimen::whereUuid($row['uuid'])->firstOrFail();
        $collectionId = $stored->collection->id;
        $firstContainerId = $stored->container->first()->id;
        $this->assertSame($patient->id, $stored->getRawOriginal($ownerColumn));
        $this->assertNull($stored->getRawOriginal($patientType === Person::class ? 'preperson_id' : 'person_id'));
        $this->assertSame($legalEntityUuid, $stored->managingOrganization->value);
        $this->assertSame($uuids['employee'], $stored->registeredBy->value);
        $this->assertSame($uuids['encounter'], $stored->context->value);
        // The package computes references; a caller-supplied isReferenced flag cannot change status.
        $this->assertSame('available', $stored->status->value);
        $this->assertNull($stored->receivedTime);
        $form = $this->load($uuids['encounter']);
        $this->assertSame($row['uuid'], $form['uuid']);
        $this->assertSame('patient', $form['collectorType']);
        $this->assertSame($patient->uuid, $form['collectorId']);
        $this->assertSame($uuids['employee'], $form['registeredById']);
        $this->assertSame(array_values($row['parentIds']), $form['parentIds']);
        $this->assertSame($row['procedureId'], $form['procedureId']);
        $this->assertSame($timing, $form['collectedType']);
        if ($timing === 'period') {
            $this->assertSame('04.10.2026 — 05.10.2026', $form['collectedPeriodRange']);
            $this->assertSame('09:00', $form['collectedPeriodStartTime']);
            $this->assertSame('10:00', $form['collectedPeriodEndTime']);
            $this->assertSame('', $form['collectedDate']);
        } else {
            $this->assertSame('05.10.2026', $form['collectedDate']);
            $this->assertSame('10:15', $form['collectedTime']);
            $this->assertSame('', $form['collectedPeriodRange']);
        }
        $this->assertSame(2.5, $form['durationValue']);
        $this->assertSame(10.0, $form['quantityValue']);
        $this->assertSame('ml', $form['quantityCode']);
        $this->assertSame('aspiration', $form['methodCode']);
        $this->assertSame('arm', $form['bodySiteCode']);
        $this->assertSame('fasting', $form['fastingStatusCode']);
        $this->assertCount(2, $form['containers']);
        $this->assertSame('container', $form['containers'][0]['identifier']);
        $this->assertSame(20.5, $form['containers'][0]['capacityValue']);
        $this->assertSame(10.0, $form['containers'][0]['specimenQuantityValue']);
        $this->assertSame('heparin', $form['containers'][0]['additiveCode']);

        $remote = Arr::toSnakeCase($package['specimens'][0]);
        $remote['uuid'] = $row['uuid'];
        $remote['status'] = 'unavailable';
        $remote['status_reason'] = ['coding' => [['system' => 'specimen_invalidate_reasons', 'code' => 'used']], 'text' => ''];
        $remote['received_time'] = '2026-10-05T08:00:00Z';
        $remote['container'] = [array_replace($remote['container'][0], [
            'description' => 'Оновлена пробірка',
            'capacity' => ['value' => 0, 'system' => 'eHealth/ucum/units', 'code' => 'ml'],
        ])];
        $remote['parent'] = [end($remote['parent'])];
        $remote['collection']['quantity']['value'] = 0;
        $repository->sync($patient, [$remote]);
        $form = $this->load($uuids['encounter']);
        $this->assertSame('05.10.2026', $form['receivedDate']);
        $this->assertSame('11:00', $form['receivedTime']);
        $this->assertSame(0.0, $form['quantityValue']);
        $this->assertSame([end($row['parentIds'])], $form['parentIds']);
        $this->assertCount(1, $form['containers']);
        $this->assertSame(0.0, $form['containers'][0]['capacityValue']);
        $this->assertSame('Оновлена пробірка', $form['containers'][0]['description']);
        $stored = $stored->fresh();
        $this->assertSame('unavailable', $stored->status->value);
        $this->assertSame($collectionId, $stored->collection->id);
        $this->assertSame($firstContainerId, $stored->container->first()->id);
        $this->assertSame(1, Specimen::whereUuid($row['uuid'])->count());
        Http::assertNothingSent();
    }

    private function load(string $encounter): array
    {
        $rows = new ReflectionMethod(EncounterPackageLoader::class, 'loadSpecimens')
            ->invoke(app(EncounterPackageLoader::class), $encounter);
        $this->assertCount(1, $rows);

        return array_values($rows)[0];
    }

    public static function patientsAndTiming(): iterable
    {
        foreach ([Person::class, Preperson::class] as $patientType) {
            foreach (['date_time', 'period'] as $timing) {
                yield $patientType.' / '.$timing => [$patientType, $timing];
            }
        }
    }
}
