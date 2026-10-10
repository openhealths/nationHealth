<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\Encounter\Ehealth;
use App\Dto\Encounter\Form;
use App\Dto\FormCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class EncounterMappingTest extends TestCase
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
        $payload = $mapper->map(new FormCollection($input['outbound']), new Ehealth($input['outbound']['uuid'] ?? 'encounter', 'visit', 'episode', 'employee', $input['conditions']))->toArray();
        $this->assertSame($expected['signedJson'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $details = ['evidence' => ['ehealthInsertedAt' => 'date', 'codeCode' => '111', 'type' => 'observation']];
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), new Form(['support' => ['ehealthInsertedAt' => 'date', 'codeCode' => '301']], '12:00'))->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_full_and_selected_record_cancellation_preserve_whole_package_and_strict_ids(): void
    {
        $expected = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Mapping/encounter-baseline.json'), true)['cancellation'];
        $source = new Collection(['cancellationReason' => 'incorrect_data', 'explanatoryLetter' => 'Лист', 'cancellationReasonText' => 'Опис']);
        $mapper = app(ObjectMapperInterface::class);
        $full = $mapper->map($source, new \App\Dto\Encounter\EhealthCancellation($expected['package']))->toArray();
        $this->assertSame($expected['fullJson'], json_encode($full, JSON_THROW_ON_ERROR));
        $partial = $mapper->map($source, new \App\Dto\Encounter\EhealthCancellation($expected['package'], 'finished', ['observations' => ['observations'], 'specimens' => ['specimens']]))->toArray();
        $this->assertSame($expected['partialJson'], json_encode($partial, JSON_THROW_ON_ERROR));
        $this->assertSame('finished', $partial['encounter']['status']);
        $this->assertSame('completed', $partial['observations'][90]['status']);
        $this->assertSame('confirmed', $partial['conditions'][42]['verification_status']);
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/encounter-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/encounter-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
