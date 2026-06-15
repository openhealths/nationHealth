<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ObservationConfig;
use Illuminate\Support\Facades\Cache;

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
     * Distinct answer list bindings used by valueCodeableConcept codes.
     *
     * These must be loaded as dictionaries for the observation form to render their options.
     *
     * @return array
     */
    public function codeableConceptBindings(): array
    {
        return collect($this->valueMap())
            ->filter(static fn (array $value): bool => $value[1] === 'valueCodeableConcept')
            ->map(static fn (array $value): string => $value[0])
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
     * Build (and cache) all three derived maps from the active observation configs.
     *
     * @return array
     */
    private function maps(): array
    {
        return $this->maps ??= Cache::remember(self::CACHE_KEY, now()->addDay(), static function (): array {
            $loincCodeMap = [];
            $customCodeMap = [];
            $valueMap = [];

            foreach (ObservationConfig::whereIsActive(true)->get() as $config) {
                $valueMap[$config->code] = [
                    $config->binding ?? $config->valueRange ?? '',
                    $config->valueType,
                    $config->unit ?? ''
                ];

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

            return ['loinc' => $loincCodeMap, 'custom' => $customCodeMap, 'values' => $valueMap];
        });
    }
}
