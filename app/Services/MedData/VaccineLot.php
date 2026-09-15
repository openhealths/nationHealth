<?php

declare(strict_types=1);

namespace App\Services\MedData;

use App\Classes\MedData\Api\MedDataRequest;
use App\Services\Dictionary\Mappers\ImmunizationDictionaryMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

final class VaccineLot
{
    private const string CACHE_KEY = 'meddata.vaccine_lots';

    /**
     * Expiration date MedData puts on placeholder lots that don't describe a real vial.
     */
    private const string PLACEHOLDER_EXPIRATION_DATE = '2000-01-01';

    /**
     * @param  ImmunizationDictionaryMapper  $immunizationDictionaryMapper
     */
    public function __construct(private readonly ImmunizationDictionaryMapper $immunizationDictionaryMapper)
    {
    }

    /**
     * Get cached vaccine lots prepared for prefilling the immunization form; empty until the first sync.
     *
     * @return array
     */
    public function getLots(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    /**
     * Fetch vaccine lots from MedData and replace the cached ones.
     *
     * @return void
     * @throws ConnectionException|RequestException
     */
    public function sync(): void
    {
        Cache::forever(self::CACHE_KEY, $this->mapLots(new MedDataRequest()->getSeries()['vaccines']));
    }

    /**
     * Flatten vaccines with a known vaccine code into a list of real lots with the vaccine data needed for the form.
     *
     * @param  array  $vaccines
     * @return array
     */
    private function mapLots(array $vaccines): array
    {
        return collect($vaccines)
            ->flatMap(function (array $vaccine): array {
                $targetDiseaseCodes = $this->immunizationDictionaryMapper
                    ->targetDiseaseCodesForVaccine($vaccine['platform_esoz'] ?? '');

                if ($targetDiseaseCodes === []) {
                    return [];
                }

                $usage = $vaccine['usable_after_vaccine_with_id'][0] ?? [];

                return collect($vaccine['lots'])
                    ->reject(static fn (array $lot): bool => $lot['number'] === null
                        || $lot['expire_date'] === null
                        || $lot['expire_date'] === self::PLACEHOLDER_EXPIRATION_DATE)
                    ->map(static fn (array $lot): array => [
                        'uuid' => $lot['uuid'],
                        'number' => $lot['number'],
                        'expirationDate' => CarbonImmutable::createFromFormat('Y-m-d', $lot['expire_date'])
                            ->format(config('app.date_format')),
                        'vaccineCode' => $vaccine['platform_esoz'],
                        'vaccineName' => $vaccine['display_name'],
                        'manufacturer' => $vaccine['manufacturer'],
                        'doseQuantityValue' => $usage['quantity_per_dose'] ?? null,
                        'doseQuantityCode' => $usage['unit_of_dose'] ?? null,
                        'routeCode' => $usage['method_of_administerting'] ?? null,
                        'seriesDoses' => $usage['recommended_doses'] ?? null,
                        'targetDiseaseCodes' => $targetDiseaseCodes
                    ])
                    ->all();
            })
            ->sortByDesc(static fn (array $lot): string => CarbonImmutable::createFromFormat(
                config('app.date_format'),
                $lot['expirationDate']
            )->format('Y-m-d'))
            ->values()
            ->all();
    }
}
