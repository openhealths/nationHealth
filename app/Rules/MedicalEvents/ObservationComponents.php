<?php

declare(strict_types=1);

namespace App\Rules\MedicalEvents;

use App\Repositories\Repository;
use App\Rules\InDictionary;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;

/**
 * Checks the components of an observation against the configuration of its code: required and allowed components,
 * their uniqueness, answer lists and boundaries, and the observation value calculated from the component scores.
 */
class ObservationComponents implements ValidationRule
{
    /**
     * @param  array  $observation  The observation the validated components belong to
     */
    public function __construct(protected array $observation)
    {
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $config = Repository::observationConfig()->componentMap()[$this->observation['codeCode'] ?? ''] ?? null;

        if ($config === null || !is_array($value)) {
            return;
        }

        $filledComponents = collect($value)->filter(
            static fn (array $component): bool => ($component['valueCode'] ?? '') !== ''
                || ($component['valueQuantityValue'] ?? '') !== ''
        );
        $configuredComponents = collect($config['components'])->keyBy('code');

        foreach ($filledComponents->pluck('codeCode')->diff($configuredComponents->keys())->unique() as $code) {
            $fail(__('observations.validation.component_not_allowed', ['component' => $code]));
        }

        if ($config['unique']) {
            $duplicateCodes = $filledComponents->pluck('codeCode')
                ->duplicates()
                ->unique()
                ->intersect($configuredComponents->keys());

            foreach ($duplicateCodes as $code) {
                $fail(__('observations.validation.component_duplicate', [
                    'component' => $this->componentName($configuredComponents, $code)
                ]));
            }
        }

        foreach ($configuredComponents as $code => $configured) {
            $component = $filledComponents->firstWhere('codeCode', $code);
            $name = $this->componentName($configuredComponents, $code);

            if ($component === null) {
                if ($configured['required']) {
                    $fail(__('observations.validation.component_required', ['component' => $name]));
                }

                continue;
            }

            if ($configured['valueType'] === 'valueCodeableConcept') {
                $this->validateAnswer($component, $configured, $name, $fail);
            }

            if ($configured['valueType'] === 'valueQuantity') {
                $this->validateQuantity($component, $configured, $name, $fail);
            }
        }

        if ($config['calculation'] === 'SUM') {
            $sum = $filledComponents->sum(
                static fn (array $component): int|float => $config['scores'][$component['valueSystem'] ?? '']
                    [$component['valueCode'] ?? ''] ?? 0
            );
            $observationValue = $this->observation['valueQuantityValue'] ?? '';

            if (!is_numeric($observationValue) || (float)$observationValue !== (float)$sum) {
                $fail(__('observations.validation.value_calculation_mismatch', ['sum' => $sum]));
            }
        }
    }

    /**
     * The answer has to come from the answer list configured for the component.
     *
     * @param  array  $component
     * @param  array  $configured
     * @param  string  $name
     * @param  Closure  $fail
     * @return void
     */
    private function validateAnswer(array $component, array $configured, string $name, Closure $fail): void
    {
        $isValid = ($component['valueSystem'] ?? '') === $configured['binding'];

        if ($isValid) {
            new InDictionary($configured['binding'])->validate(
                'valueCode',
                $component['valueCode'] ?? '',
                static function () use (&$isValid): void {
                    $isValid = false;
                }
            );
        }

        if (!$isValid) {
            $fail(__('observations.validation.component_value_invalid', ['component' => $name]));
        }
    }

    /**
     * The quantity has to be a number within the boundaries configured for the component.
     *
     * @param  array  $component
     * @param  array  $configured
     * @param  string  $name
     * @param  Closure  $fail
     * @return void
     */
    private function validateQuantity(array $component, array $configured, string $name, Closure $fail): void
    {
        $quantity = $component['valueQuantityValue'] ?? '';

        if (!is_numeric($quantity)) {
            $fail(__('observations.validation.component_value_invalid', ['component' => $name]));

            return;
        }

        $isBelowMin = $configured['min'] !== null && $quantity < $configured['min'];
        $isAboveMax = $configured['max'] !== null && $quantity > $configured['max'];

        if ($isBelowMin || $isAboveMax) {
            $fail(__('observations.validation.component_out_of_range', [
                'component' => $name,
                'min' => $configured['min'],
                'max' => $configured['max']
            ]));
        }
    }

    /**
     * Name of the component the way its dictionary labels it.
     *
     * @param  Collection  $configuredComponents
     * @param  string  $code
     * @return string
     */
    private function componentName(Collection $configuredComponents, string $code): string
    {
        $system = $configuredComponents->get($code)['system'] ?? '';

        return (string)dictionary()->basics()->byName($system)->flattenedChildValues()->get($code);
    }
}
