<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\CarePlan;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Repositories\CarePlanRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanPeriodValidationTest extends TestCase
{
    public static function dateFormats(): array
    {
        return [
            'dots' => ['d.m.Y', '10.11.2026', '11.11.2026', '09.11.2026', '31.02.2026'],
            'ISO' => ['Y-m-d', '2026-11-10', '2026-11-11', '2026-11-09', '2026-02-31'],
            'slashes' => ['d/m/Y', '10/11/2026', '11/11/2026', '09/11/2026', '31/02/2026'],
        ];
    }

    public static function validDateFormats(): array
    {
        return array_map(static fn (array $dates): array => array_slice($dates, 0, 3), self::dateFormats());
    }

    public static function startDateFormats(): array
    {
        return array_map(static fn (array $dates): array => array_slice($dates, 0, 2), self::dateFormats());
    }

    #[DataProvider('validDateFormats')]
    public function test_configured_dates_validate_and_reach_payload_without_day_month_swapping(string $format, string $start, string $end): void
    {
        config(['app.date_format' => $format]);
        $form = $this->form($start, $end);
        $form->validate();
        $this->assertSame('2026-11-10', $form->periodStartIsoDate());
        $this->assertSame('2026-11-11', $form->periodEndIsoDate());
        $rules = array_intersect_key($form->rulesForSigning(), array_flip(['periodStart', 'periodEnd']));
        $this->assertTrue(Validator::make($form->toArray(), $rules)->passes());

        $payload = (new CarePlanRepository())->formatCarePlanRequest(array_replace($form->toArray(), [
            'periodStart' => $form->periodStartIsoDate(), 'periodEnd' => $form->periodEndIsoDate(),
        ]), null, ['addresses' => []], '11111111-1111-4111-8111-111111111111');
        $this->assertSame('2026-11-10', CarbonImmutable::parse($payload['period']['start'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertSame('2026-11-11', CarbonImmutable::parse($payload['period']['end'])->setTimezone(config('app.timezone'))->toDateString());
    }

    #[DataProvider('dateFormats')]
    public function test_configured_format_rejects_earlier_end_and_impossible_dates(string $format, string $start, string $end, string $earlier, string $invalid): void
    {
        config(['app.date_format' => $format]);
        $form = $this->form($start, $earlier);
        $validator = Validator::make($form->toArray(), $form->rules(), $form->messages());
        $this->assertTrue($validator->fails());
        $this->assertSame([__('care-plan.period_end_before_start')], $validator->errors()->get('periodEnd'));

        foreach ([[$invalid, $end], [$start, $invalid]] as [$periodStart, $periodEnd]) {
            $form = $this->form($periodStart, $periodEnd);
            $this->assertTrue(Validator::make($form->toArray(), $form->rules())->fails());
        }
    }

    #[DataProvider('startDateFormats')]
    public function test_equal_and_omitted_end_work_for_each_configured_format(string $format, string $start): void
    {
        config(['app.date_format' => $format]);
        foreach ([$start, ''] as $end) {
            $form = $this->form($start, $end);
            $form->validate();
            $this->assertSame($end === '' ? null : '2026-11-10', $form->periodEndIsoDate());
        }
    }

    public function test_form_blocks_end_before_start_with_localized_field_error(): void
    {
        $form = $this->form('10.10.2026', '09.10.2026');

        try {
            $form->validate();
            $this->fail('An end before the start must be rejected before signing.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('care-plan.period_end_before_start')], $exception->errors()['form.periodEnd']);
        }
    }

    public function test_equal_later_and_omitted_end_dates_remain_valid(): void
    {
        foreach (['10.10.2026', '11.10.2026', ''] as $end) {
            $validated = $this->form('10.10.2026', $end)->validate();
            $this->assertSame($end, $validated['periodEnd']);
        }
    }

    private function form(string $start, string $end): CarePlanForm
    {
        $component = new class extends Component
        {};
        $form = new CarePlanForm($component, 'form');
        $form->category = 'THERAPY';
        $form->title = 'Test plan';
        $form->termsOfService = 'EPISODE';
        $form->periodStart = $start;
        $form->periodEnd = $end;

        return $form;
    }
}
