<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents\Concerns;

use App\Enums\CarePlan\ClosedActivityDocumentStatus;
use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** @mixin BaseRepository */
trait FindsOpenActivityRequests
{
    public function findOpenForActivity(CarePlanActivity $activity): Collection
    {
        return $this->model->newQuery()
            ->whereHas('basedOn', fn ($query) => $query->where('value', $activity->uuid))
            ->get(['uuid', 'status'])
            ->filter(static fn (Model $row): bool => ClosedActivityDocumentStatus::fromStored((string) $row->status) === null)
            ->values();
    }
}
