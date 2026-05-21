<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class ImmunizationDoseQuantity extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'immunization_id',
        'value',
        'comparator',
        'unit',
        'system',
        'code'
    ];

    protected $hidden = [
        'id',
        'immunization_id',
        'created_at',
        'updated_at'
    ];
}
