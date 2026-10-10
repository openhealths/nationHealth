<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\CarePlan\EhealthDraft;
use App\Dto\CarePlan\Form;
use App\Dto\CarePlan\Model;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanSync;
use App\Core\Arr;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Encounter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class CarePlanModelSourceMappingTest extends TestCase
{
    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $inputs = require $directory.'/care-plan-model-inputs.php';
        $baseline = json_decode(file_get_contents($directory.'/care-plan-model-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($inputs as $name => $input) {
            yield $name => [$input, $baseline['cases'][$name]];
        }
    }

    #[DataProvider('contracts')]
    public function test_preloaded_model_preserves_form_and_signed_draft_contracts_without_io(array $input, array $expected): void
    {
        $plan = (new CarePlan())->forceFill($input['model']);
        $plan->setRelation('person', null);
        $plan->setRelation('author', null);
        $plan->setRelation('encounter', $input['encounterUuid'] ? new Encounter(['uuid' => $input['encounterUuid']]) : null);
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $mapper = app(ObjectMapperInterface::class);
        $payload = $mapper->map($plan, new EhealthDraft($input['employeeUuid']))->toArray();
        $this->assertSame($expected['payload'], $payload);
        $this->assertSame($expected['signingJson'], json_encode(Arr::toSnakeCase($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame($expected['form'], $mapper->map($plan, Form::class)->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_remote_sync_keeps_local_fallback_text_but_clears_remote_only_fields_with_the_previous_defaults(): void
    {
        $now = Carbon::parse('2026-10-05T12:00:00Z');
        $target = new Model('Local title', 'Local description', 'Local note', $now);
        $dto = app(ObjectMapperInterface::class)->map(new CarePlanSync([
            'id' => null, 'uuid' => 'remote-uuid', 'title' => '0', 'description' => '', 'note' => null,
        ]), $target);
        $this->assertSame([
            'uuid' => 'remote-uuid', 'status' => 'active', 'title' => 'Local title', 'terms_of_service' => null,
            'period_start' => $now, 'period_end' => null, 'description' => 'Local description', 'note' => 'Local note',
        ], $dto->toSyncAttributes());
    }

    public function test_remote_sync_keeps_empty_primary_uuid_and_inserted_timestamp_fallback_instead_of_reinterpreting_them(): void
    {
        $dto = app(ObjectMapperInterface::class)->map(new CarePlanSync([
            'id' => '', 'uuid' => 'ignored-alias', 'status' => 'new', 'title' => 'Remote title',
            'ehealth_inserted_at' => '2026-10-05T12:00:00Z',
            'terms_of_service' => ['coding' => [['code' => 'INPATIENT']]],
        ]), new Model(mappedAt: Carbon::parse('2026-10-06T12:00:00Z')));
        $this->assertSame('', $dto->uuid);
        $this->assertSame('2026-10-05T12:00:00Z', $dto->period_start);
        $this->assertSame('INPATIENT', $dto->terms_of_service);
        $this->assertSame('Remote title', $dto->title);
    }
}
