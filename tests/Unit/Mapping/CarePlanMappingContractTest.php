<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Dto\CarePlan\Model as PlanData;
use App\Dto\CarePlan\Form as PlanForm;
use App\Dto\CarePlanActivity\Model as ActivityData;
use App\Dto\CarePlanActivity\Form as ActivityForm;
use App\Dto\FormCollection;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanMappingContractTest extends TestCase
{
    #[DataProvider('contracts')]
    public function testIndependentAuditContractsAndActualApiContentWithoutMappingIo(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $source = new FormCollection($input['data']);
        try {
            $form = $input['target'] === 'plan' ? PlanForm::class : ActivityForm::class;
            $data = $input['target'] === 'plan' ? PlanData::class : ActivityData::class;
            $result = match ($input['direction']) {
                'inbound' => $form::fromSource($source, $input['context'])->toArray(),
                'roundTrip' => $data::fromSource(new FormCollection($form::fromSource($source, $input['context'])->toArray()), $input['context'])->toArray(),
                default => $data::fromSource($source, $input['context'])->toArray(),
            };
            $this->assertArrayNotHasKey('exception', $expected);
            $this->assertSame(json_decode($expected['json'], true, flags: JSON_THROW_ON_ERROR), $result);
            $this->assertSame($expected['json'], json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            if (isset($expected['signingJson'])) {
                $api = $input['target'] === 'plan' ? app(CarePlanApi::class) : app(ActivityApi::class);
                $this->assertSame($expected['signingJson'], json_encode($api->buildCreateContent($result), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
            if (isset($expected['uiAttributes'])) {
                $repository = new class ($result) extends CarePlanActivityRepository
                {
                    public function __construct(private array $snapshot)
                    {
                    }
                    public function getResource(int|CarePlanActivity $id): array
                    {
                        return $this->snapshot;
                    }
                };
                $this->assertSame($expected['uiAttributes'], $repository->presentForUi(new CarePlanActivity())->getAttributes());
            }
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($expected['exception'] ?? null, $exception::class);
            $this->assertSame($expected['message'], $exception->getMessage());
        } finally {
            $this->assertSame($input['data'], $source->all());
            $this->assertSame([], $queries);
            Http::assertNothingSent();
        }
    }

    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/care-plan-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/care-plan-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
