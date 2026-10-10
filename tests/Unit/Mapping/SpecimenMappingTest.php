<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\FormCollection;
use App\Dto\Specimen\Ehealth;
use App\Dto\Specimen\EhealthCancellation;
use App\Dto\Specimen\EhealthProcess;
use App\Dto\Specimen\EhealthStatusReason;
use App\Dto\Specimen\Form;
use App\Enums\Specimen\StatusReasonType;
use App\Livewire\Specimen\Forms\SpecimenActionForm;
use App\Livewire\Specimen\Forms\SpecimenCancellationForm;
use App\Livewire\Specimen\Forms\SpecimenForm;
use App\Livewire\Specimen\SpecimenCancellation;
use App\Livewire\Specimen\SpecimenCreate;
use App\Livewire\Specimen\SpecimenIndex;
use App\Models\LegalEntity;
use App\Repositories\MedicalEvents\SpecimenRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\Support\EncounterPackageHarness as EncounterPackageBuilder;
use Tests\Support\EncounterPackageHarness as EncounterPackageLoader;
use Tests\TestCase;

class SpecimenMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
    }

    #[DataProvider('contracts')]
    public function test_old_wire_hydration_form_source_and_package_callers_without_mapping_io(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        // Mapping succeeds with explicit context even when no legal entity is bound.
        app()->forgetInstance('legalEntity');
        $dto = $mapper->map(new FormCollection($input['outbound']), new Ehealth('specimen-fixed', 'legal-entity', 'employee', $input['encounter']));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($dto->toArray())));
        $form = new SpecimenForm(new SpecimenCreate(), 'form');
        $form->specimen = $input['outbound'];
        $formDto = $mapper->map($form, new Ehealth('specimen-fixed', 'legal-entity', 'employee', $input['encounter']));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($formDto->toArray())));
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());
        $this->assertTrue(array_is_list($dto->toArray()['container']));
        if (isset($dto->toArray()['parent'])) {
            $this->assertTrue(array_is_list($dto->toArray()['parent']));
        }

        app()->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => 'legal-entity']));
        if (!empty($input['encounter'])) {
            $documents = $this->package($input['outbound'], $input['encounter'])['specimens'];
            $this->assertSame($expected['json'], $this->documentJson($documents[0]));
            $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($documents[0])));
        }
        $this->mock(SpecimenRepository::class, function ($mock) use ($input): void {
            $mock->shouldReceive('get')->once()->with('encounter')->andReturn([42 => $input['inbound']]);
        });
        $this->assertSame([42 => $expected['form']], new ReflectionMethod(EncounterPackageLoader::class, 'loadSpecimens')
            ->invoke(app(EncounterPackageLoader::class), 'encounter'));
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    #[DataProvider('actions')]
    public function test_old_process_reason_and_raw_cancellation_contracts_without_io(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $this->assertSame($expected['processJson'], $this->documentJson($mapper->map(new Collection($input['data']), EhealthProcess::class)->toArray()));
        $form = new SpecimenActionForm(new SpecimenIndex(), 'form');
        $form->receivedDate = $input['data']['receivedDate'];
        $form->receivedTime = $input['data']['receivedTime'];
        $this->assertSame($expected['processJson'], $this->documentJson($mapper->map($form, EhealthProcess::class)->toArray()));
        foreach (['reject' => StatusReasonType::REJECT, 'invalidate' => StatusReasonType::INVALIDATE] as $operation => $type) {
            $payload = $mapper->map(new Collection(['reason' => $input['data'][$operation.'Reason']]), new EhealthStatusReason($type))->toArray();
            $this->assertSame($expected[$operation.'Json'], $this->documentJson($payload));
        }
        $cancel = $mapper->map(new Collection(['cancellationReason' => $input['reason']]), new EhealthCancellation($input['snapshot']))->toArray();
        $this->assertSame($expected['cancelJson'], $this->documentJson($cancel));
        $cancelForm = new SpecimenCancellationForm(new SpecimenCancellation(), 'form');
        $cancelForm->cancellationReason = $input['reason'];
        $this->assertSame($expected['cancelJson'], $this->documentJson($mapper->map($cancelForm, new EhealthCancellation($input['snapshot']))->toArray()));
        foreach (array_diff_key($input['snapshot'], ['status' => true, 'status_reason' => true]) as $key => $value) {
            $this->assertSame($value, $cancel[$key]);
        }
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_package_resolves_references_from_both_resource_lists_and_ignores_input_flags(): void
    {
        app()->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => 'legal-entity']));
        $contracts = require dirname(__DIR__, 2).'/Fixtures/Mapping/specimen-inputs.php';
        $row = $contracts['full sparse containers']['outbound'];
        $payload = app(EncounterPackageBuilder::class)->toFhir([
            'specimens' => [
                9 => array_replace($row, ['uuid' => 'observation-reference']),
                42 => array_replace($row, ['uuid' => 'report-reference']),
                78 => array_replace($row, ['uuid' => 'unreferenced']),
            ],
            'observations' => [$this->observation('observation-reference'), $this->observation('')],
            'diagnosticReports' => [(require dirname(__DIR__, 2).'/Fixtures/Mapping/diagnostic-report-inputs.php')['minimal']['outbound'] + ['specimenIds' => [5 => 'report-reference', 42 => 'report-reference', 78 => '']]],
            'encounter' => $this->encounter(),
        ], ['encounter' => 'encounter', 'visit' => 'visit', 'episode' => 'episode', 'employee' => 'employee'])['specimens'];
        $this->assertTrue(array_is_list($payload));
        $this->assertSame(['unavailable', 'unavailable', 'available'], array_column($payload, 'status'));
        foreach ([0, 1] as $index) {
            $this->assertSame('used', $payload[$index]['statusReason']['coding'][0]['code']);
            $this->assertSame('2026-10-05T08:00:00Z', $payload[$index]['receivedTime']);
        }
        $this->assertArrayNotHasKey('statusReason', $payload[2]);
        $this->assertArrayNotHasKey('receivedTime', $payload[2]);
    }

    public function test_package_generates_missing_and_null_uuids_once_but_preserves_empty_and_existing_ids(): void
    {
        app()->instance('legalEntity', new LegalEntity()->forceFill(['uuid' => 'legal-entity']));
        $contracts = require dirname(__DIR__, 2).'/Fixtures/Mapping/specimen-inputs.php';
        $row = $contracts['minimal']['outbound'];
        unset($row['uuid']);
        $payload = app(EncounterPackageBuilder::class)->toFhir([
            'specimens' => [$row, $row + ['uuid' => null], $row + ['uuid' => ''], $row + ['uuid' => 'existing']],
            'encounter' => $this->encounter(),
        ], ['encounter' => 'encounter', 'visit' => 'visit', 'episode' => 'episode', 'employee' => 'employee'])['specimens'];
        $this->assertTrue(Str::isUuid($payload[0]['id']));
        $this->assertTrue(Str::isUuid($payload[1]['id']));
        $this->assertNotSame($payload[0]['id'], $payload[1]['id']);
        $this->assertSame('', $payload[2]['id']);
        $this->assertSame('existing', $payload[3]['id']);
    }

    private function encounter(): array
    {
        return ['periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00', 'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => 'employee', 'referralType' => '', 'diagnoses' => []];
    }

    private function observation(string $specimen): array
    {
        return (require dirname(__DIR__, 2).'/Fixtures/Mapping/observation-inputs.php')['minimal']['outbound'] + ['specimenId' => $specimen];
    }

    private function package(array $specimen, string $encounter): array
    {
        return app(EncounterPackageBuilder::class)->toFhir([
            'specimens' => [42 => $specimen],
            'observations' => !empty($specimen['isReferenced']) ? [$this->observation($specimen['uuid'])] : [],
            'encounter' => $this->encounter(),
        ], ['encounter' => $encounter, 'visit' => 'visit', 'episode' => 'episode', 'employee' => 'employee']);
    }

    private function documentJson(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/specimen-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/specimen-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }

    public static function actions(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/specimen-action-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/specimen-action-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
