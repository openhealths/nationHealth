<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Models\ObservationConfig;
use App\Services\MedicalEvents\ObservationConfigService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ObservationConfigurationSync implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300;

    /**
     * Pull observation configurations and upsert them into observation_configs.
     *
     * Fetches everything on the first run (empty table) and only records changed since the
     * latest known updated_at on subsequent runs, via the updated_at_from date filter.
     *
     * @param  ObservationConfigService  $observationConfigService
     * @return void
     */
    public function handle(ObservationConfigService $observationConfigService): void
    {
        try {
            $query = [];
            $latestUpdatedAt = ObservationConfig::max('ehealth_updated_at');

            if ($latestUpdatedAt !== null) {
                $query['updated_at_from'] = Str::before($latestUpdatedAt, 'T');
            }

            $page = 1;

            do {
                $response = EHealth::configuration()->getObservations([...$query, 'page' => $page]);

                foreach ($response->getData() as $item) {
                    $this->upsertItem($item);
                }

                $page++;
            } while ($response->isNotLast());

            $observationConfigService->flush();
        } catch (Exception $exception) {
            Log::channel('task_scheduling')->error('Observation configurations sync failed.', [
                'message' => $exception->getMessage()
            ]);
        }
    }

    /**
     * Map a single API item to the table columns and upsert it.
     *
     * @param  array  $item
     * @return void
     */
    private function upsertItem(array $item): void
    {
        $settings = $item['settings'] ?? [];

        ObservationConfig::updateOrCreate(
            ['code' => $item['code'], 'system' => $item['system']],
            [
                'is_active' => $item['is_active'],
                'category' => $this->extractCategories($settings),
                'value_type' => $this->mapValueType($settings['RESULT_TYPE']['check'] ?? null),
                'binding' => $settings['RESULT_BINDING']['check'][0] ?? null,
                'unit' => $settings['RESULT_QUANTITY_CODES'][0]['check'][0]['code'] ?? null,
                'value_range' => $this->mapRange($settings),
                'ehealth_updated_at' => $item['updated_at'] ?? null
            ]
        );
    }

    /**
     * Extract the list of category codes from the settings payload.
     *
     * @param  array  $settings
     * @return array
     */
    private function extractCategories(array $settings): array
    {
        return array_map(static fn (array $entry): string => $entry['code'], $settings['CATEGORY']['check'] ?? []);
    }

    /**
     * Translate the eHealth RESULT_TYPE into a FHIR value type, e.g. quantity => valueQuantity.
     *
     * @param  string|null  $resultType
     * @return string|null
     */
    private function mapValueType(?string $resultType): ?string
    {
        return $resultType ? 'value' . Str::studly($resultType) : null;
    }

    /**
     * Build the numeric range string (e.g. 0-100) from settings.RESULT_BOUNDARIES.
     *
     * @param  array  $settings
     * @return string|null
     */
    private function mapRange(array $settings): ?string
    {
        $check = $settings['RESULT_BOUNDARIES'][0]['check'] ?? null;

        if (!isset($check['min'], $check['max'])) {
            return null;
        }

        return "{$check['min']}-{$check['max']}";
    }
}
