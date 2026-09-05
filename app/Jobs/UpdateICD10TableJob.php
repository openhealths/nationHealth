<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateICD10TableJob implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::channel('task_scheduling')->info('Updating ICD-10 codes begins.');

            $response = EHealth::dictionary()->getMany(['name' => 'eHealth/ICD10_AM/condition_codes']);

            $dictionary = BasicDictionaryCollection::make($response->getData())
                ->byName('eHealth/ICD10_AM/condition_codes', false)
                ->asLargeDictionary()
                ->toArray();

            $now = CarbonImmutable::now();

            $data = [];
            foreach ($dictionary as $key => $value) {
                $data[] = [
                    'code' => $key,
                    'description' => $value['description'],
                    'is_active' => $value['is_active'],
                    'child_values' => json_encode($value['child_values'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }

            // Upsert by chunks: the table is never empty for readers, and a failed run leaves the previous data intact
            $chunks = array_chunk($data, 10000);
            foreach ($chunks as $chunk) {
                DB::table('icd_10')->upsert(
                    $chunk,
                    ['code'],
                    ['description', 'is_active', 'child_values', 'updated_at']
                );
            }

            Log::channel('task_scheduling')->info('Updating ICD-10 codes successfully ended.');
        } catch (Exception $exception) {
            Log::channel('task_scheduling')->error('Error while updating ICD-10 codes.', [
                'message' => $exception->getMessage()
            ]);
        }
    }
}
