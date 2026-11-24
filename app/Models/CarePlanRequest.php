<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EHealthDateCast;
use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarePlanRequest extends BaseCarePlan
{
    protected $attributes = [
        'addresses' => '[]',
    ];

    protected $casts = [
        'period_start' => EHealthDateCast::class,
        'period_end' => EHealthDateCast::class,
        'co_authors' => 'array',
        'episodes' => 'array',
        'medical_records' => 'array',
        'version' => 'integer',
    ];

    #[Scope]
    protected function forEditor(Builder $query, int $legalEntityId, int $userId): Builder
    {
        return $query->where('legal_entity_id', $legalEntityId)
            ->where('user_id', $userId);
    }

    #[Scope]
    protected function editable(Builder $query): Builder
    {
        return $query->whereNull('care_plan_id')
            ->whereDoesntHave(
                'ehealthLinks',
                static fn (Builder $links): Builder => $links->where('status', JobStatus::PENDING->value)
            );
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }
}
