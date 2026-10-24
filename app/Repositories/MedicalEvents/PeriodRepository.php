<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use Illuminate\Database\Eloquent\Model;
use App\Models\MedicalEvents\Sql\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PeriodRepository extends BaseRepository
{
    /** Bypass the presentation cast, which truncates seconds and converts to a localized string. */
    public function syncExact(Model $owner, ?array $data): void
    {
        $key = ['periodable_type' => $owner->getMorphClass(), 'periodable_id' => $owner->getKey()];
        if ($data === null) {
            DB::table('periods')->where($key)->delete();

            return;
        }
        $values = [];
        foreach (['start', 'end'] as $boundary) {
            $values[$boundary] = isset($data[$boundary])
                ? CarbonImmutable::parse($data[$boundary], 'UTC')->utc()->format('Y-m-d H:i:s.u')
                : null;
        }
        DB::table('periods')->updateOrInsert($key, $values);
    }

    public function toResource(Period $period): array
    {
        $data = [];
        foreach (['start', 'end'] as $boundary) {
            $value = $period->getRawOriginal($boundary);
            $data[$boundary] = $value === null ? null : rtrim(rtrim(CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u'), '0'), '.').'Z';
        }

        return $data;
    }

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

        $existing = $periodable->wasRecentlyCreated ? null : $periodable->{$relation};

        if ($existing) {
            // A period without an end has none, so an end stored before is cleared
            $existing->update(['end' => null, ...$periodData]);
        } else {
            $periodable->{$relation}()->create($periodData);
        }
    }
}
