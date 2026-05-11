<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Range extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'id',
        'low_id',
        'high_id',
        'created_at',
        'updated_at'
    ];

    public function low(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'low_id');
    }

    public function high(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'high_id');
    }
}
