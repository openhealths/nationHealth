<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class SampledData extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'origin',
        'period',
        'factor',
        'lower_limit',
        'upper_limit',
        'dimensions',
        'data'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];
}
