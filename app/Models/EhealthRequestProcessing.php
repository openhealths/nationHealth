<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EhealthRequestProcessing extends Model
{
    protected $fillable = [
        'response_data'
    ];

    protected $casts = [
        'response_data' => 'array'
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(EhealthLink::class, 'ehealth_link_id');
    }
}
