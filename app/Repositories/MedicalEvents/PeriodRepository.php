<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use Illuminate\Database\Eloquent\Model;

class PeriodRepository extends BaseRepository
{
    /**
     * Sync period data for a model.
     *
     * @param  Model  $model
     * @param  array  $periodData
     * @return void
     */
    public function sync(Model $model, array $periodData): void
    {
        if (empty($periodData)) {
            // If no period data, remove existing period
            $model->period()->delete();

            return;
        }

        $model->period()->updateOrCreate([], [
            'start' => $periodData['start'],
            'end' => $periodData['end'] ?? null
        ]);
    }
}
