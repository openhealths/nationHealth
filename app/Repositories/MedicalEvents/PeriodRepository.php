<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use Illuminate\Database\Eloquent\Model;

class PeriodRepository extends BaseRepository
{
    /**
     * Sync period data for a model.
     *
     * @param  Model  $periodable
     * @param  null|array  $periodData
     * @param  string  $relation
     * @return void
     */
    public function sync(Model $periodable, ?array $periodData, string $relation = 'period'): void
    {
        if (empty($periodData)) {
            $periodable->{$relation}()->delete();

            return;
        }

        $existing = $periodable->{$relation};

        if ($existing) {
            $existing->update($periodData);
        } else {
            $periodable->{$relation}()->create($periodData);
        }
    }
}
