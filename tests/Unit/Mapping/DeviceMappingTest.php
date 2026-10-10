<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\Device\Ehealth;
use App\Dto\Device\Form;
use App\Dto\FormCollection;
use App\Repositories\MedicalEvents\DeviceRepository;
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

class DeviceMappingTest extends TestCase
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
        $dto = $mapper->map(new FormCollection($input['outbound']), new Ehealth('device-fixed', 'encounter', 'recorder'));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($dto->toArray())));
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());

        $documents = $this->package([42 => $input['outbound']])['devices'];
        $this->assertTrue(array_is_list($documents));
        $this->assertSame($expected['json'], $this->documentJson($documents[0]));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($documents[0])));
        foreach (['name', 'identifier', 'property'] as $field) {
            if (isset($documents[0][$field])) {
                $this->assertTrue(array_is_list($documents[0][$field]));
            }
        }

        $this->mock(DeviceRepository::class, function ($mock) use ($input): void {
            $mock->shouldReceive('get')->once()->with('encounter')->andReturn([42 => $input['inbound']]);
        });
        $loaded = new ReflectionMethod(EncounterPackageLoader::class, 'loadDevices')
            ->invoke(app(EncounterPackageLoader::class), 'encounter');
        $this->assertSame([42 => $expected['form']], $loaded);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_caller_generates_missing_or_null_ids_and_preserves_empty_id(): void
    {
        $row = ['primarySource' => true, 'typeCode' => 'device-type'];
        $documents = $this->package([9 => $row, 20 => $row + ['uuid' => null], 42 => $row + ['uuid' => '']])['devices'];
        $this->assertTrue(array_is_list($documents));
        $this->assertTrue(Str::isUuid($documents[0]['id']));
        $this->assertTrue(Str::isUuid($documents[1]['id']));
        $this->assertNotSame($documents[0]['id'], $documents[1]['id']);
        $this->assertSame('', $documents[2]['id']);
        $this->assertSame([], $this->package([])['devices']);
    }

    private function package(array $devices): array
    {
        return app(EncounterPackageBuilder::class)->toFhir([
            'devices' => $devices,
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
        $expected = json_decode(file_get_contents($directory.'/device-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/device-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
