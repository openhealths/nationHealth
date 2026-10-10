<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\CarePlanActivity\Ehealth;
use App\Dto\CarePlanActivity\EhealthDetail;
use App\Dto\CarePlanActivity\Form;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Period;
use App\Models\MedicalEvents\Sql\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class CarePlanActivityMappingTest extends TestCase
{
    #[DataProvider('contracts')]
    public function test_model_maps_without_io_and_preserves_old_payload_and_json(array $input, array $expected): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        date_default_timezone_set('Europe/Kyiv');
        $now = Carbon::parse('2026-10-05 09:15:30', 'Europe/Kyiv');
        $this->travelTo($now);
        $plan = new CarePlan(['uuid' => 'care-plan']);
        $activity = new CarePlanActivity($input['model']);
        $activity->setRelation('carePlan', $plan);
        $activity->setRelation('author', new Employee(['uuid' => 'employee']));
        $quantity = isset($input['quantity']) ? new Quantity($input['quantity']) : null;
        $activity->setRelation('quantityQuantity', $quantity);
        $activity->setRelation('dailyAmountQuantity', null);
        $activity->setRelation('kindConcept', null);
        $period = null;
        if (isset($input['period'])) {
            $period = new Period();
            $period->setRawAttributes($input['period']);
        }
        $activity->setRelation('scheduledPeriod', $period);
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $detail = $mapper->map($activity, new EhealthDetail(
            $quantity ? $quantity->code : ($activity->quantity_code ?: null),
            $activity->daily_amount_code ?? ($quantity ? $quantity->code : ($activity->quantity_code ?: null)),
            $input['allowed'] ?? [],
            $now,
            Carbon::parse(convertToEHealthISO8601(($input['planStart'] ?? '2026-10-01').' 00:00:00'))->utc(),
        ));
        $payload = $mapper->map($activity, new Ehealth($detail))->toArray();
        $this->assertSame($expected['payload'], $payload);
        $this->assertSame($expected['json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame($expected['form'], $mapper->map($activity, Form::class)->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $baseline = json_decode(file_get_contents($directory.'/care-plan-activity-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/care-plan-activity-inputs.php' as $name => $input) {
            yield $name => [$input, $baseline[$name]];
        }
    }
}
