<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Dto\CarePlan\Model;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanSourceClassTest extends TestCase
{
    #[DataProvider('contracts')]
    public function testRealFormMatchesIndependentLegacySigningWithoutIoOrSecrets(array $input, array $expected): void
    {
        config(['app.date_format' => $input['dateFormat']]);
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $form = new CarePlanForm(new class extends Component
        {}, 'form');
        $form->fill($input['fields']);
        $before = $form->toArray();
        $prepared = app(CarePlanRepository::class)->formContext(array_replace($before, $input['resolved']));
        $actual = Model::fromForm($form, $prepared['data'], $input['context'])->toArray();
        $this->assertSame(json_decode($expected['resourceJson'], true, flags: JSON_THROW_ON_ERROR), $actual);
        $json = json_encode(app(CarePlanApi::class)->buildCreateContent($actual), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->assertSame($expected['signingJson'], $json);
        $this->assertStringNotContainsString('synthetic-secret-never-in-payload', $json);
        $this->assertSame($before, $form->toArray());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }
    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $expected = json_decode(file_get_contents($directory.'/care-plan-form-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (require $directory.'/care-plan-form-inputs.php' as $name => $input) {
            yield $name => [$input, $expected[$name]];
        }
    }
}
