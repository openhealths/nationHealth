<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\FormCollection;
use App\Dto\Immunization\Ehealth;
use App\Dto\Immunization\Form;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ImmunizationMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_independent_old_payload_and_hydration_contracts_without_io(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map(new FormCollection($input['outbound']), new Ehealth('immunization', 'encounter', 'employee', '12:00'))->toArray();
        $this->assertSame($expected['signedJson'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $details = ['evidence' => ['ehealthInsertedAt' => 'date', 'codeCode' => '111', 'type' => 'observation']];
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/immunization-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/immunization-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
