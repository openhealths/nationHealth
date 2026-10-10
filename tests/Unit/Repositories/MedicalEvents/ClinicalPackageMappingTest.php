<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\Collections\ServiceCollection;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\EncounterPackageHarness;
use Tests\TestCase;

class ClinicalPackageMappingTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('resourcesAndPatients')]
    public function test_actual_package_repository_and_form_workflows_keep_owner_identifiers_and_nested_values(string $resource, string $patientType): void
    {
        Http::fake();
        Http::preventStrayRequests();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $patient = $patientType::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE', 'birth_date' => '1990-01-01', ...($patientType === Person::class ? ['patient_signed' => true, 'process_disclosure_data_consent' => true] : [])]);
        $this->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => (string) Str::uuid()]));
        $dictionary = Mockery::mock(DictionaryManager::class);
        $dictionary->shouldReceive('services')->andReturn(new ServiceCollection());
        $this->instance(DictionaryManager::class, $dictionary);
        $uuids = array_combine(['encounter', 'visit', 'episode', 'employee'], array_map(static fn (): string => (string) Str::uuid(), range(1, 4)));
        $fixtures = dirname(__DIR__, 3).'/Fixtures/Mapping';
        [$section,$fixture,$case,$model] = match ($resource) {
            'condition' => ['conditions', 'condition', 'full sparse', \App\Models\MedicalEvents\Sql\Condition::class],
            'immunization' => ['immunizations', 'immunization', 'full sparse duplicate disease', \App\Models\MedicalEvents\Sql\Immunization::class],
            'clinicalImpression' => ['clinicalImpressions', 'clinical-impression', 'full sparse duplicates', \App\Models\MedicalEvents\Sql\ClinicalImpression::class],
            'observation' => ['observations', 'observation', 'full sparse components', \App\Models\MedicalEvents\Sql\Observation::class],
            'diagnosticReport' => ['diagnosticReports', 'diagnostic-report', 'full sparse dedup', \App\Models\MedicalEvents\Sql\DiagnosticReport::class],
        };
        $row = (require $fixtures.'/'.$fixture.'-inputs.php')[$case]['outbound'];
        $row['uuid'] = (string) Str::uuid();
        foreach (['asserterEmployeeId', 'performerEmployeeId', 'assessorEmployeeId', 'codeValue', 'basedOnIdentifier', 'divisionId', 'resultsInterpreterEmployeeId', 'reactionOn', 'deviceId', 'specimenId'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (string) Str::uuid();
            }
        }
        if ($resource === 'condition') {
            $row['evidenceDetails'] = [];
        }
        // Existing SQL schema allows integer doses with uppercase units; fractional wire values have golden tests.
        if ($resource === 'immunization') {
            $row['doseQuantityValue'] = 2;
            $row['doseQuantityUnit'] = 'ML';
        }
        if ($resource === 'clinicalImpression') {
            $row['previous'] = [['id' => (string) Str::uuid()]];
            $row['problems'] = [['id' => (string) Str::uuid()]];
            $row['findings'] = [['id' => (string) Str::uuid(), 'type' => 'observation', 'basis' => 'Причина']];
            $row['supportingInfo'] = [['uuid' => (string) Str::uuid(), 'type' => 'procedure']];
        }
        if ($resource === 'diagnosticReport') {
            $row['performerEmployeeIds'] = [(string) Str::uuid()];
            $row['specimenIds'] = [(string) Str::uuid()];
            $row['usedReferences'] = [['id' => (string) Str::uuid()]];
        }
        $workflow = app(EncounterPackageHarness::class);
        $encounter = ['periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00', 'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => $uuids['employee'], 'referralType' => '', 'diagnoses' => []];
        $package = $workflow->toFhir([$section => [42 => $row], 'encounter' => $encounter], $uuids);
        $repository = Repository::$resource();
        $repository->store([$package[$section][0]], $patient);
        $stored = $model::whereUuid($row['uuid'])->firstOrFail();
        $this->assertSame($patient->id, $stored->getRawOriginal($patientType === Person::class ? 'person_id' : 'preperson_id'));
        $loader = 'load'.ucfirst($section);
        $loaded = new ReflectionMethod(EncounterPackageHarness::class, $loader)->invoke($workflow, $resource === 'condition' ? ['uuid' => $uuids['encounter'], 'diagnoses' => []] : $uuids['encounter']);
        $this->assertCount(1, $loaded);
        $form = array_values($loaded)[0];
        $this->assertSame($row['uuid'], $form['uuid']);
        switch ($resource) {
            case 'condition':
                $this->assertSame($uuids['encounter'], $stored->context->value);
                $this->assertSame($row['asserterEmployeeId'], $form['asserterEmployeeId']);
                $this->assertSame('05.10.2026', $form['onsetDate']);
                $this->assertSame('10:15', $form['onsetTime']);
                $this->assertSame([['code' => 'arm']], $form['bodySites']);
                $this->assertCount(2, $form['evidenceCodes']);
                break;
            case 'immunization':
                $this->assertSame($row['performerEmployeeId'], $form['performerEmployeeId']);
                $this->assertSame('05.11.2026', $form['expirationDate']);
                $this->assertSame('09:32', $form['expirationTime']);
                $this->assertSame(2, (int) $form['doseQuantityValue']);
                $this->assertSame(2, $form['vaccinationProtocols'][0]['doseSequence']);
                $this->assertSame([['code' => 'medical']], $form['reasons']);
                break;
            case 'clinicalImpression':
                $this->assertSame($row['assessorEmployeeId'], $form['assessorEmployeeId']);
                $this->assertSame('05.10.2026', $form['effectivePeriodStartDate']);
                $this->assertSame('10:00', $form['effectivePeriodStartTime']);
                $this->assertSame($row['previous'][0]['id'], $form['previous'][0]['id']);
                $this->assertSame($row['findings'][0]['id'], $form['findings'][0]['id']);
                $this->assertSame('Причина', $form['findings'][0]['basis']);
                break;
            case 'observation':
                $this->assertSame($row['performerEmployeeId'], $form['performerEmployeeId']);
                $this->assertSame('05.10.2026', $form['issuedDate']);
                $this->assertSame('10:15', $form['issuedTime']);
                $this->assertSame(1.5, (float) $form['valueQuantityValue']);
                $this->assertSame('value', $form['components'][0]['valueCode']);
                $this->assertSame($row['specimenId'], $form['specimenId']);
                $updated = $package[$section][0];
                unset($updated['specimen']);
                $repository->store([$updated], $patient);
                $this->assertNull($stored->fresh()->getRawOriginal('specimen_id'));
                $this->assertSame(1, $model::whereUuid($row['uuid'])->count());
                break;
            case 'diagnosticReport':
                $this->assertSame($row['codeValue'], $form['codeValue']);
                $this->assertSame($row['specimenIds'], $form['specimenIds']);
                $this->assertSame($row['usedReferences'], $form['usedReferences']);
                $this->assertSame($row['resultsInterpreterEmployeeId'], $form['resultsInterpreterEmployeeId']);
                $this->assertSame('10:00', $form['effectiveTime']);
                $this->assertSame('D02', $form['conclusionCode']);
                break;
        }
        Http::assertNothingSent();
    }

    public static function resourcesAndPatients(): iterable
    {
        foreach (['condition', 'immunization', 'clinicalImpression', 'observation', 'diagnosticReport'] as $resource) {
            foreach ([Person::class, Preperson::class] as $patient) {
                yield $resource.' '.$patient => [$resource, $patient];
            }
        }
    }
}
