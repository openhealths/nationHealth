<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EhealthLink extends Model
{
    protected $fillable = [
        'linkable_type',
        'linkable_id',
        'ehealth_job_id',
        'entity',
        'href'
    ];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(EhealthJob::class);
    }

    public function processingData(): HasMany
    {
        return $this->hasMany(EhealthRequestProcessing::class);
    }
}
