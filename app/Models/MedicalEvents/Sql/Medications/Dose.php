<?php

namespace App\Models\MedicalEvents\Sql\Medications;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class Dose extends Model
{
    use HasCamelCasing;

    protected $table = 'dosages';

    protected $fillable = [
        'value',
        'unit',
        'system',
        'code',
    ];

    protected $hidden = [
        'id',
        'value',
        'unit',
        'system',
        'code',
    ];

    protected $casts = [
        'value' => 'integer',
        'unit' => 'string',
        'system' => 'string',
        'code' => 'string',
    ];
}
