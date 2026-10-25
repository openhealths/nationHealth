<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\DeviceDispense\Ehealth;
use App\Dto\DeviceDispense\Form;
use App\Dto\FormCollection;
use App\Repositories\MedicalEvents\ConditionRepository;
use App\Repositories\MedicalEvents\DeviceDispenseRepository;
use App\Repositories\MedicalEvents\DiagnosticReportRepository;
use App\Repositories\MedicalEvents\EncounterRepository;
use App\Repositories\MedicalEvents\EpisodeRepository;
use App\Repositories\MedicalEvents\ObservationRepository;
use App\Repositories\MedicalEvents\ProcedureRepository;
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

class DeviceDispenseMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
    }

    #[DataProvider('contracts')]
    public function test_old_wire_hydration_and_actual_package_callers_without_io(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $dto = $mapper->map(new FormCollection($input['outbound']), new Ehealth('dispense-fixed', 'encounter'));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($dto->toArray())));
        $form = $mapper->map(new Collection($input['inbound']), new Form($input['detailsMap']))->toArray();
        $this->assertSame($expected['form'], $form);
        $this->assertArrayNotHasKey('detailsMap', $form);
        $this->assertTrue(array_is_list($form['supportingInfo']));

        $documents = $this->package([42 => $input['outbound']])['deviceDispenses'];
        $this->assertTrue(array_is_list($documents));
        $this->assertSame($expected['json'], $this->documentJson($documents[0]));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($documents[0])));
        $this->assertTrue(array_is_list($documents[0]['details']));
        if (isset($documents[0]['supportingInfo'])) {
            $this->assertTrue(array_is_list($documents[0]['supportingInfo']));
        }

        $this->mock(DeviceDispenseRepository::class, function ($mock) use ($input): void {
            $mock->shouldReceive('get')->once()->with('encounter')->andReturn([42 => $input['inbound']]);
        });
        $types = collect($input['inbound']['supportingInfo'] ?? [])
            ->filter()->groupBy(static fn (array $row) => data_get($row, 'identifier.type.coding.0.code'));
        foreach ([ConditionRepository::class => 'condition', ObservationRepository::class => 'observation',
            DiagnosticReportRepository::class => 'diagnostic_report', ProcedureRepository::class => 'procedure',
            EncounterRepository::class => 'encounter', EpisodeRepository::class => 'episode'] as $repository => $type) {
            $ids = ($types->get($type, collect()))->pluck('identifier.value')->filter()->unique()->values()->all();
            $this->mock($repository, function ($mock) use ($ids, $input, $type): void {
                $mock->shouldReceive('getDetailsMapByUuids')->once()->with($ids)
                    ->andReturn($type === 'condition' ? $input['detailsMap'] : []);
            });
        }
        $loaded = new ReflectionMethod(EncounterPackageLoader::class, 'loadDeviceDispenses')
            ->invoke(app(EncounterPackageLoader::class), 'encounter');
        $this->assertSame([42 => $expected['form']], $loaded);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_caller_generates_missing_or_null_ids_and_preserves_empty_id(): void
    {
        $row = array_values(require dirname(__DIR__, 2).'/Fixtures/Mapping/device-dispense-inputs.php')[0]['outbound'];
        unset($row['uuid']);
        $documents = $this->package([9 => $row, 20 => $row + ['uuid' => null], 42 => $row + ['uuid' => '']])['deviceDispenses'];
        $this->assertTrue(Str::isUuid($documents[0]['id']));
        $this->assertTrue(Str::isUuid($documents[1]['id']));
        $this->assertNotSame($documents[0]['id'], $documents[1]['id']);
        $this->assertSame('', $documents[2]['id']);
        $this->assertSame([], $this->package([])['deviceDispenses']);
        $this->mock(DeviceDispenseRepository::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->with('encounter')->andReturn([]);
        });
        $this->assertSame([], new ReflectionMethod(EncounterPackageLoader::class, 'loadDeviceDispenses')
            ->invoke(app(EncounterPackageLoader::class), 'encounter'));
    }

    private function package(array $dispenses): array
    {
        return app(EncounterPackageBuilder::class)->toFhir([
            'deviceDispenses' => $dispenses,
            'encounter' => [
                'periodDate' => '05.10.2026', 'periodStart' => '10:00', 'periodEnd' => '11:00',
                'classCode' => 'AMB', 'typeCode' => 'consultation', 'performerId' => 'recorder',
                'referralType' => '', 'diagnoses' => [],
            ],
        ], ['encounter' => 'encounter', 'visit' => 'visit', 'episode' => 'episode', 'employee' => 'recorder']);
    }

    private function documentJson(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/device-dispense-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/device-dispense-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
