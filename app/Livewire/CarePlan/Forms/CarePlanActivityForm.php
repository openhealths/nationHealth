<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use App\Core\Arr;
use App\Enums\CarePlanActivityKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class CarePlanActivityForm extends Form
{
    public static function buildCreateContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'author' => ['required', 'array', 'min:1'],
            'author.*.identifier.value' => ['required', 'uuid'],
            'carePlan.identifier.value' => ['required', 'uuid'],
            'detail.kind' => ['required', 'in:'.implode(',', array_column(CarePlanActivityKind::cases(), 'value'))],
        ])->validate();
        $detail = $resource['detail'];
        $kind = CarePlanActivityKind::from($detail['kind']);
        $requiresProgram = $kind === CarePlanActivityKind::MEDICATION_REQUEST;
        $rules = [
            'program' => [$requiresProgram ? 'required' : 'sometimes', 'array'],
            'program.identifier.value' => ['required_with:program', 'uuid'],
            'quantity.value' => [isset($detail['program']) || $kind === CarePlanActivityKind::SERVICE_REQUEST ? 'required' : 'sometimes', $kind === CarePlanActivityKind::MEDICATION_REQUEST ? 'numeric' : 'integer', 'gt:0'],
            'scheduledPeriod' => [isset($detail['program']) && !isset($detail['scheduledTiming']) && !isset($detail['scheduledString']) ? 'required' : 'sometimes', 'array'],
            'scheduledPeriod.start' => ['required_with:scheduledPeriod', 'date'],
            'scheduledPeriod.end' => ['required_with:scheduledPeriod', 'date', 'after_or_equal:scheduledPeriod.start'],
        ];
        Validator::make($detail, $rules, [], ['quantity.value' => __('care-plan.ehealth_fields.quantity_value')])->validate();
        if ($kind !== CarePlanActivityKind::MEDICATION_REQUEST && isset($detail['quantity']['value'])) {
            $detail['quantity']['value'] = (int) $detail['quantity']['value'];
        }
        if (isset($detail['productReference']) === isset($detail['productCodeableConcept'])) {
            throw ValidationException::withMessages(['product' => 'Exactly one product variant is required.']);
        }
        $content = array_intersect_key($resource, array_flip(['id', 'author', 'carePlan']));
        $content['detail'] = array_intersect_key($detail, array_flip(['kind', 'productReference', 'productCodeableConcept', 'reasonCode', 'reasonReference', 'goal', 'quantity', 'location', 'performer', 'dailyAmount', 'scheduledPeriod', 'scheduledTiming', 'scheduledString', 'description', 'program', 'doNotPerform']));
        $content['detail']['status'] = 'scheduled';

        return self::resourcePayload($content);
    }

    /** TV39 options are multiples of any packaging for the device type within the inclusive period cap. */
    public static function deviceQuantityOptions(array $packagingCounts, int $maxDailyCount, string $start, string $end): array
    {
        if ($start === '' || $end === '' || $maxDailyCount < 1) {
            return [];
        }
        if (!Validator::make(['start' => $start, 'end' => $end], ['start' => ['required', 'date'], 'end' => ['required', 'date']])->passes()) {
            return [];
        }
        $periodStart = CarbonImmutable::parse($start)->startOfDay();
        $periodEnd = CarbonImmutable::parse($end)->startOfDay();
        if ($periodEnd->lessThan($periodStart)) {
            return [];
        }
        $limit = $maxDailyCount * ((int) $periodStart->diffInDays($periodEnd) + 1);
        $options = [];
        foreach (array_unique($packagingCounts) as $count) {
            $count = (int) $count;
            if ($count < 1) {
                continue;
            }
            for ($quantity = $count; $quantity <= $limit; $quantity += $count) {
                $options[$quantity] = $quantity;
            }
        }
        ksort($options);

        return array_values($options);
    }

    public static function buildCancelContent(array $snapshot, array $reason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid'], 'detail' => ['required', 'array']])->validate();
        $snapshot['detail']['status_reason'] = $reason;

        return $snapshot;
    }

    public static function buildCompleteContent(array $reason, array $outcomes): array
    {
        Validator::make(['reason' => $reason, 'outcomes' => $outcomes], [
            'reason.coding' => ['required', 'array', 'min:1'],
            'outcomes' => ['required', 'array', 'min:1'],
            'outcomes.*.coding' => ['required', 'array', 'min:1'],
        ])->validate();

        return ['detail' => ['status_reason' => $reason], 'outcome_codeable_concept' => $outcomes];
    }

    private static function resourcePayload(array $data): array
    {
        $result = [];
        foreach (Arr::toSnakeCase($data) as $key => $value) {
            if (is_array($value)) {
                $value = self::resourcePayload($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $result[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($result) : $result;
    }
}
