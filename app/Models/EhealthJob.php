<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EhealthJob extends Model
{
    protected $fillable = [
        'processing_method',
        'status',
        'request_data',
        'response_data',
        'eta'
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'eta' => 'datetime'
    ];

    public function links(): HasMany
    {
        return $this->hasMany(EhealthLink::class, 'ehealth_job_id');
    }
}
