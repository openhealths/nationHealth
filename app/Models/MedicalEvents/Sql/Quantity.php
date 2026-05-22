<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;

class Quantity extends Model
{
    protected $fillable = [
        'value',
        'comparator',
        'unit',
        'system',
        'code'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = ['value' => 'float'];
}
