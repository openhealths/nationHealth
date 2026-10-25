<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\Composition\Ehealth;
use App\Dto\Composition\EhealthCancellation;
use App\Dto\Composition\Extension;
use App\Dto\FormCollection;
use App\Enums\Composition\CompositionType;
use App\Livewire\Composition\Forms\CompositionCancellationForm;
use App\Livewire\Composition\Forms\CompositionForm;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tests\TestCase;

class CompositionMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function testMainJsonAndActualCallersArePreservedWithoutIo(array $input, array $expected): void
    {
        config(['app.timezone' => $input['timezone'] ?? 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set($input['timezone'] ?? 'Europe/Kyiv');
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $class = match ($input['kind']) {
            'newborn' => CompositionForm::class,
            'disability' => CompositionTempDisabilityForm::class,
            'cancel' => CompositionCancellationForm::class,
        };
        $form = new $class(new class extends Component
        {}, 'form');
        $form->fill($input['fields']);
        $before = $form->toArray();
        $author = '80000000-0000-4000-8000-000000000008';
        $id = '70000000-0000-4000-8000-000000000007';
        if ($form instanceof CompositionCancellationForm) {
            $type = CompositionType::from($input['type']);
            $payload = EhealthCancellation::fromForm($form, $id, $type)->toArray();
            $caller = $form->toCancellationPayload($id, $type);
        } else {
            $payload = Ehealth::fromForm($form, $author)->toArray();
            $caller = $form->toPayload($author);
        }

        $this->assertSame($expected['payload'], $payload);
        $this->assertSame($expected['json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame($payload, $caller);
        $this->assertSame($before, $form->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/composition-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/composition-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }

    #[DataProvider('literalValues')]
    public function testTypedExtensionValuesPreserveEmptyLiterals(array $row): void
    {
        $mapper = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor());

        $this->assertSame($row, $mapper->map(new FormCollection($row), Extension::class)->toArray());
    }

    public static function literalValues(): iterable
    {
        yield 'explicit false' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => false]];
        yield 'string zero' => [['valueCode' => 'TEST_VALUE', 'valueString' => '0']];
        yield 'empty string' => [['valueCode' => 'TEST_VALUE', 'valueString' => '']];
    }
}
