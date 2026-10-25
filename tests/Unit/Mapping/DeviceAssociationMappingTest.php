<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\DeviceAssociation\Ehealth;
use App\Dto\DeviceAssociation\Form;
use App\Dto\FormCollection;
use App\Repositories\MedicalEvents\DeviceAssociationRepository;
use Carbon\CarbonImmutable;
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

class DeviceAssociationMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        CarbonImmutable::setTestNow('2026-10-05T10:15:37+03:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('contracts')]
    public function test_old_wire_hydration_and_real_callers_without_io(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map(new FormCollection($input['outbound']), new Ehealth('association-fixed', 'encounter', 'recorder'))->toArray();
        $this->assertSame($expected['signedJson'], $this->documentJson($payload));
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());

        $document = $this->package([42 => $input['outbound']])['deviceAssociations'];
        $this->assertTrue(array_is_list($document));
        $this->assertSame($expected['json'], $this->documentJson($document[0]));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($document[0])));

        $this->mock(DeviceAssociationRepository::class, function ($mock) use ($input): void {
            $mock->shouldReceive('get')->once()->with('encounter')->andReturn([42 => $input['inbound']]);
        });
        $loaded = new ReflectionMethod(EncounterPackageLoader::class, 'loadDeviceAssociations')
            ->invoke(app(EncounterPackageLoader::class), 'encounter');
        $this->assertSame([42 => $expected['form']], $loaded);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    #[DataProvider('associationGroups')]
    public function test_clock_pair_order_and_existing_timestamps_match_old_collection(array $input, array $expected): void
    {
        $document = $this->package($input)['deviceAssociations'];
        $this->assertTrue(array_is_list($document));
        $this->assertSame($expected['json'], $this->documentJson($document));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($document)));
    }

    public function test_uuid_generation_is_owned_by_caller(): void
    {
        $row = ['deviceId' => 'device', 'status' => 'attached', 'primarySource' => true];
        $document = $this->package([8 => $row, 10 => $row + ['uuid' => null], 42 => $row + ['uuid' => '']])['deviceAssociations'];
        $this->assertTrue(Str::isUuid($document[0]['id']));
        $this->assertTrue(Str::isUuid($document[1]['id']));
        $this->assertNotSame($document[0]['id'], $document[1]['id']);
        $this->assertSame('', $document[2]['id']);
    }

    private function package(array $associations): array
    {
        return app(EncounterPackageBuilder::class)->toFhir([
            'deviceAssociations' => $associations,
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
        $expected = json_decode(file_get_contents($directory.'/device-association-baseline.json'), true, flags: JSON_THROW_ON_ERROR)['rows'];
        foreach (require $directory.'/device-association-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }

    public static function associationGroups(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/device-association-baseline.json'), true, flags: JSON_THROW_ON_ERROR)['groups'];
        foreach (require $directory.'/device-association-groups.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
