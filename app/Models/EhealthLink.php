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
        'href',
        'error',
        'error_code',
        'status'
    ];

    protected $casts = [
        'error' => 'array'
    ];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(EhealthJob::class, 'ehealth_job_id');
    }

    public function processingData(): HasMany
    {
        return $this->hasMany(EhealthRequestProcessing::class, 'ehealth_link_id');
    }
}
