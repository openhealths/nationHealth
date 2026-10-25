<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\Composition\Form as FormData;
use App\Dto\FormCollection;
use App\Enums\Composition\CompositionRelation;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompositionFormMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function testActualRefinementAndContinuationPreserveBaselineWithoutIo(array $input, array $expected): void
    {
        config(['app.timezone' => $input['timezone'] ?? 'Europe/Kyiv', 'app.date_format' => $input['dateFormat'] ?? 'd.m.Y']);
        date_default_timezone_set($input['timezone'] ?? 'Europe/Kyiv');
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $form = new CompositionTempDisabilityForm(new class extends Component
        {}, 'form');
        $form->fill(['category' => 'SICKNESS', 'subjectUuid' => $input['subjectUuid'], 'sectionFocusUuid' => $input['sectionFocusUuid'], 'isAccident' => true, 'treatmentViolation' => 'previous-ui-value']);
        $mode = CompositionRelation::from($input['mode']);
        $previous = $input['previous'];
        $fields = FormData::fromSource(new FormCollection($previous), $form->subjectUuid, $form->sectionFocusUuid, $mode)->toArray();
        if ($mode === CompositionRelation::APPENDS) {
            $form->prefillForContinuation($previous);
        } else {
            $form->prefillFromPrevious($previous);
        }

        $this->assertSame($expected, $form->toArray());
        $this->assertSame(array_intersect_key($expected, $fields), $fields);
        $this->assertSame($input['previous'], $previous);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/composition-form-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/composition-form-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
