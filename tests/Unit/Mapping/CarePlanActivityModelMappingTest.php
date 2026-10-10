<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanActivitySync;
use App\Dto\CarePlanActivity\Model;
use App\Livewire\CarePlan\CarePlanShow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class CarePlanActivityModelMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_local_and_remote_sources_keep_the_old_write_fields_without_io(array $input, array $expected): void
    {
        if (isset($input['form'])) {
            $source = new CarePlanShow();
            $source->activityForm = $input['form'];
            $source->linkedGrounds = $input['grounds'];
            $target = new Model($input['program'], $input['start'], $input['end']);
        } else {
            $source = new CarePlanActivitySync($input['remote']);
            $target = new Model();
        }
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $attributes = app(ObjectMapperInterface::class)->map($source, $target)->toArray();
        // Persistence compares field values; Carbon is normalized as in the independently captured baseline.
        $actual = json_decode(json_encode($attributes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_remote_aliases_choose_whole_daily_amount_and_reference_objects_without_merging_them(): void
    {
        $dto = app(ObjectMapperInterface::class)->map(new CarePlanActivitySync(['detail' => [
            'dailyAmount' => ['value' => 0], 'daily_amount' => ['value' => 9, 'system' => 'ignored', 'code' => 'ignored'],
            'product_reference' => [], 'productReference' => ['identifier' => ['value' => 'ignored']],
        ]]), Model::class);
        $this->assertSame(0, $dto->daily_amount);
        $this->assertNull($dto->daily_amount_system);
        $this->assertNull($dto->daily_amount_code);
        $this->assertNull($dto->product_reference);
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $baseline = json_decode(file_get_contents($directory.'/care-plan-activity-write-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/care-plan-activity-write-inputs.php' as $name => $input) {
            yield $name => [$input, $baseline[$name]];
        }
    }
}
