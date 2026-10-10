<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\ProcedureRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\EncounterPackageHarness as EncounterPackageBuilder;
use Tests\Support\EncounterPackageHarness as EncounterPackageLoader;
use Tests\TestCase;

class ProcedureRepositoryMappingTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('patientsAndTiming')]
    public function test_actual_package_sync_and_form_loader_preserve_owner_timing_and_fhir_links(string $patientType, string $timing): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $patient = $patientType::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE', 'birth_date' => '1990-01-01', ...($patientType === Person::class ? ['patient_signed' => true, 'process_disclosure_data_consent' => true] : [])]);
        $legal = (string) Str::uuid();
        $this->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => $legal]));
        $uuids = array_combine(['encounter', 'visit', 'episode', 'employee'], array_map(static fn (): string => (string) Str::uuid(), range(1, 4)));
        $inputs = require dirname(__DIR__, 3).'/Fixtures/Mapping/procedure-inputs.php';
        $row = $inputs[$timing === 'period' ? 'period' : 'full encounter']['outbound'];
        foreach (['uuid', 'codeValue', 'performerEmployeeId', 'basedOnIdentifier', 'divisionId'] as $key) {
            $row[$key] = (string) Str::uuid();
        }
        $row['reasonReferences'] = [['id' => (string) Str::uuid(), 'type' => 'condition']];
        $row['complicationDetails'] = [['id' => (string) Str::uuid()]];
        $row['usedReferences'] = [['id' => (string) Str::uuid()]];
        $row['focalDevice'] = [['manipulatedId' => (string) Str::uuid(), 'actionCode' => 'implantation']];
        $package = app(EncounterPackageBuilder::class)->toFhir(['procedures' => [42 => $row], 'encounter' => ['periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00', 'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => $uuids['employee'], 'referralType' => '', 'diagnoses' => []]], $uuids);
        $remote = Arr::toSnakeCase($package['procedures'][0]);
        $remote['uuid'] = $remote['id'];
        unset($remote['id']);
        $repository = app(ProcedureRepository::class);
        $repository->sync($patient, [$remote]);
        $stored = Procedure::whereUuid($row['uuid'])->firstOrFail();
        $this->assertSame($patient->id, $stored->getRawOriginal($patientType === Person::class ? 'person_id' : 'preperson_id'));
        $this->assertSame($legal, $stored->managingOrganization->value);
        $this->assertSame($uuids['employee'], $stored->recordedBy->value);
        $this->assertSame($uuids['encounter'], $stored->encounter->value);
        $this->assertSame($row['codeValue'], $stored->code->value);
        $this->assertSame($row['basedOnIdentifier'], $stored->basedOn->value);
        $form = $this->load($uuids['encounter']);
        $this->assertSame($row['uuid'], $form['uuid']);
        $this->assertSame($timing, $form['performedType']);
        if ($timing === 'period') {
            $this->assertSame('04.10.2026', $form['performedPeriodStartDate']);
            $this->assertSame('09:00', $form['performedPeriodStartTime']);
            $this->assertSame('10:00', $form['performedPeriodEndTime']);
        } else {
            $this->assertSame('05.10.2026', $form['performedDate']);
            $this->assertSame('10:15', $form['performedTime']);
        }
        $this->assertSame($row['reasonReferences'][0]['id'], $form['reasonReferences'][0]['id']);
        $this->assertSame($row['complicationDetails'][0]['id'], $form['complicationDetails'][0]['id']);
        $this->assertSame([['code' => '301']], $form['usedCodes']);
        $this->assertSame($row['usedReferences'], $form['usedReferences']);
        $this->assertSame($row['focalDevice'], $form['focalDevice']);
        $remote['note'] = 'Оновлено';
        $remote['used_codes'] = [];
        $remote['used_references'] = [];
        $remote['focal_device'] = [];
        $repository->sync($patient, [$remote]);
        $form = $this->load($uuids['encounter']);
        $this->assertSame('Оновлено', $form['note']);
        $this->assertSame([], $form['usedCodes']);
        $this->assertSame([], $form['usedReferences']);
        $this->assertSame([], $form['focalDevice']);
        $this->assertSame(1, Procedure::whereUuid($row['uuid'])->count());
        Http::assertNothingSent();
    }

    private function load(string $encounter): array
    {
        $rows = new ReflectionMethod(EncounterPackageLoader::class, 'loadProcedures')->invoke(app(EncounterPackageLoader::class), $encounter);
        $this->assertCount(1, $rows);

        return array_values($rows)[0];
    }

    public static function patientsAndTiming(): iterable
    {
        foreach ([Person::class, Preperson::class] as $patient) {
            foreach (['date_time', 'period'] as $timing) {
                yield $patient.' / '.$timing => [$patient, $timing];
            }
        }
    }
}
