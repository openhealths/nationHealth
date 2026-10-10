<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\Episode\Ehealth;
use App\Dto\Episode\EhealthCancellation;
use App\Dto\Episode\EhealthClosure;
use App\Dto\Episode\EhealthUpdate;
use App\Dto\Episode\Form;
use App\Dto\FormCollection;
use App\Enums\Episode\Status;
use App\Livewire\Episode\EpisodeCreate;
use App\Livewire\Episode\EpisodeIndex;
use App\Livewire\Episode\Forms\EpisodeCancellationForm;
use App\Livewire\Episode\Forms\EpisodeClosingForm;
use App\Livewire\Episode\Forms\EpisodeForm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class EpisodeMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_old_create_update_hydration_with_both_sources_without_io(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        Http::fake();
        $queries = [];
        DB::listen(static function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $form = new EpisodeForm(new EpisodeCreate(), 'form');
        $form->fill($input['outbound']);
        foreach ([new FormCollection($input['outbound']), $form] as $source) {
            $this->assertSame($expected['signedJson'], json_encode($mapper->map($source, new Ehealth('episode', Status::ACTIVE, 'legal-entity', 'employee', '05.10.2026', '10:00'))->toArray(), JSON_THROW_ON_ERROR));
            $this->assertSame($expected['updateJson'], json_encode($mapper->map($source, EhealthUpdate::class)->toArray(), JSON_THROW_ON_ERROR));
        }
        $this->assertSame($expected['form'], $mapper->map(new Collection($input['inbound']), Form::class)->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    #[DataProvider('actions')]
    public function test_cancel_close_exact_json_and_explicit_null_with_both_sources(string $operation, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv', 'app.date_format' => 'd.m.Y']);
        date_default_timezone_set('Europe/Kyiv');
        $form = $operation === 'cancel' ? new EpisodeCancellationForm(new EpisodeIndex(), 'form') : new EpisodeClosingForm(new EpisodeIndex(), 'form');
        $form->fill($expected['input']);
        foreach ([new FormCollection($expected['input']), $form] as $source) {
            $target = $operation === 'cancel' ? new EhealthCancellation('Опис') : new EhealthClosure('Завершено');
            $this->assertSame($expected['json'], json_encode(app(ObjectMapperInterface::class)->map($source, $target)->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    public static function contracts(): iterable
    {
        $dir = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($dir.'/episode-baseline.json'), true);
        foreach (require $dir.'/episode-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }

    public static function actions(): iterable
    {
        $expected = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Mapping/episode-baseline.json'), true);
        foreach ($expected as $name => $row) {
            if (str_starts_with($name, 'cancel ') || str_starts_with($name, 'close ')) {
                yield $name => [strtok($name, ' '), $row];
            }
        }
    }
}
