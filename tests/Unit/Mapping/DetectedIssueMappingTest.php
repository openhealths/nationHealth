<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Core\Arr;
use App\Dto\DetectedIssue\Ehealth;
use App\Dto\DetectedIssue\Form;
use App\Dto\FormCollection;
use App\Repositories\MedicalEvents\DetectedIssueRepository;
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

class DetectedIssueMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
    }

    #[DataProvider('contracts')]
    public function test_independent_old_contracts_and_real_package_callers(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map(new FormCollection($input['outbound']), new Ehealth('issue-fixed', 'encounter', 'recorder'))->toArray();
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($payload)));
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());

        $document = $this->package([9 => $input['outbound']])['detectedIssues'];
        $this->assertTrue(array_is_list($document));
        $this->assertCount(1, $document);
        $this->assertSame($expected['json'], $this->documentJson($document[0]));
        $this->assertSame($expected['signedJson'], $this->documentJson(Arr::toSnakeCase($document[0])));

        $this->mock(DetectedIssueRepository::class, function ($mock) use ($input): void {
            $mock->shouldReceive('get')->once()->with('encounter')->andReturn([9 => $input['inbound']]);
        });
        $loaded = new ReflectionMethod(EncounterPackageLoader::class, 'loadDetectedIssues')
            ->invoke(app(EncounterPackageLoader::class), 'encounter');
        $this->assertSame([9 => $expected['form']], $loaded);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_caller_generates_ids_only_for_missing_or_null_uuid_and_keeps_empty_uuid(): void
    {
        $row = ['subjectId' => 'device', 'primarySource' => true];
        $issues = $this->package([8 => $row, 20 => $row + ['uuid' => null], 42 => $row + ['uuid' => '']])['detectedIssues'];
        $this->assertTrue(array_is_list($issues));
        $this->assertTrue(Str::isUuid($issues[0]['id']));
        $this->assertTrue(Str::isUuid($issues[1]['id']));
        $this->assertNotSame($issues[0]['id'], $issues[1]['id']);
        $this->assertSame('', $issues[2]['id']);
        $this->assertSame([], $this->package([])['detectedIssues']);
    }

    private function package(array $issues): array
    {
        return app(EncounterPackageBuilder::class)->toFhir([
            'detectedIssues' => $issues,
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
        $expected = json_decode(file_get_contents($directory.'/detected-issue-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/detected-issue-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
