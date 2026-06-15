<?php

declare(strict_types=1);

namespace App\Models;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class ObservationConfig extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'code',
        'system',
        'is_active',
        'category',
        'value_type',
        'binding',
        'unit',
        'value_range',
        'ehealth_updated_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'category' => 'array'
    ];
}
