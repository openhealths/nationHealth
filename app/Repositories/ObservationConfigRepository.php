<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ObservationConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ObservationConfigRepository
{
    private const string CACHE_KEY = 'observation_configs:maps';
    private const string SYSTEM_LOINC = 'eHealth/LOINC/observation_codes';
    private const string SYSTEM_CUSTOM = 'eHealth/custom/observation_codes';

    /**
     * @var array|null
     */
    private ?array $maps = null;

    /**
     * Category to LOINC codes map (mirrors the legacy config observation.category_codes.loinc).
     *
     * @return array
     */
    public function loincCodeMap(): array
    {
        return $this->maps()['loinc'];
    }

    /**
     * Category to custom codes map (mirrors the legacy config observation.category_codes.custom).
     *
     * @return array
     */
    public function customCodeMap(): array
    {
        return $this->maps()['custom'];
    }

    /**
     * Code to [binding|range, valueType, unit] map (mirrors the legacy config observation.code_values).
     *
     * @return array
     */
    public function valueMap(): array
    {
        return $this->maps()['values'];
    }

    /**
     * Code to components config map, only for codes whose configuration defines components.
     *
     * Each entry holds the component list (code, system, valueType, binding, unit, min, max, required),
     * answer scores keyed by answer system and code, the result calculation type and the uniqueness flag.
     *
     * @return array
     */
    public function componentMap(): array
    {
        return $this->maps()['components'];
    }

    /**
     * Distinct answer list bindings used by valueCodeableConcept codes and their components.
     *
     * These must be loaded as dictionaries for the observation form to render their options.
     *
     * @return array
     */
    public function codeableConceptBindings(): array
    {
        $componentBindings = collect($this->componentMap())
            ->flatMap(static fn (array $config): array => array_column($config['components'], 'binding'));

        return collect($this->valueMap())
            ->filter(static fn (array $value): bool => $value[1] === 'valueCodeableConcept')
            ->map(static fn (array $value): string => $value[0])
            ->merge($componentBindings)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Drop the cached maps so the next read rebuilds them from the database.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->maps = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Build (and cache) all derived maps from the active observation configs.
     *
     * @return array
     */
    private function maps(): array
    {
        return $this->maps ??= Cache::remember(self::CACHE_KEY, now()->addDay(), static function (): array {
            $loincCodeMap = [];
            $customCodeMap = [];
            $valueMap = [];
            $componentMap = [];

            foreach (ObservationConfig::whereIsActive(true)->get() as $config) {
                $valueMap[$config->code] = [
                    $config->binding ?? $config->valueRange ?? '',
                    $config->valueType,
                    $config->unit ?? ''
                ];

                $settings = $config->settings ?? [];

                if (!empty($settings['COMPONENTS_TYPE'])) {
                    $componentMap[$config->code] = self::componentConfig($settings);
                }

                $codeGroup = match ($config->system) {
                    self::SYSTEM_LOINC => 'loinc',
                    self::SYSTEM_CUSTOM => 'custom',
                    default => null
                };

                if ($codeGroup === null) {
                    continue;
                }

                foreach ($config->category as $category) {
                    if ($codeGroup === 'loinc') {
                        $loincCodeMap[$category][] = $config->code;
                    } else {
                        $customCodeMap[$category][] = $config->code;
                    }
                }
            }

            return [
                'loinc' => $loincCodeMap,
                'custom' => $customCodeMap,
                'values' => $valueMap,
                'components' => $componentMap
            ];
        });
    }

    /**
     * Build the components config of a single observation code from its settings.
     *
     * @param  array  $settings
     * @return array
     */
    private static function componentConfig(array $settings): array
    {
        $requiredCodes = array_column($settings['COMPONENTS_REQUIRED']['check'] ?? [], 'code');

        $components = array_map(
            static function (array $typeRule) use ($settings, $requiredCodes): array {
                $code = $typeRule['condition']['component_code']['code'];
                $boundaries = self::componentRuleCheck($settings, 'COMPONENT_QUANTITY_BOUNDARIES', $code);

                return [
                    'code' => $code,
                    'system' => $typeRule['condition']['component_code']['system'],
                    'valueType' => 'value' . Str::studly($typeRule['check']),
                    'binding' => self::componentRuleCheck($settings, 'COMPONENTS_BINDING', $code)[0] ?? null,
                    'unit' => self::componentRuleCheck($settings, 'COMPONENTS_QUANTITY_CODES', $code)[0]['code'] ?? null,
                    'min' => $boundaries['min'] ?? null,
                    'max' => $boundaries['max'] ?? null,
                    'required' => in_array($code, $requiredCodes, true)
                ];
            },
            $settings['COMPONENTS_TYPE']
        );

        $scores = [];
        foreach ($settings['COMPONENTS_SCORE'] ?? [] as $scoreRule) {
            $answer = $scoreRule['condition']['answer'];
            $scores[$answer['system']][$answer['code']] = $scoreRule['check'];
        }

        return [
            'components' => $components,
            'scores' => $scores,
            'calculation' => $settings['RESULT_SCORE_CALCULATION']['check'] ?? null,
            'unique' => $settings['COMPONENTS_UNIQUE']['check'] ?? false
        ];
    }

    /**
     * Find the check value of a per-component settings rule by the component code.
     *
     * @param  array  $settings
     * @param  string  $key
     * @param  string  $componentCode
     * @return mixed
     */
    private static function componentRuleCheck(array $settings, string $key, string $componentCode): mixed
    {
        foreach ($settings[$key] ?? [] as $rule) {
            if (($rule['condition']['component_code']['code'] ?? null) === $componentCode) {
                return $rule['check'];
            }
        }

        return null;
    }
}
