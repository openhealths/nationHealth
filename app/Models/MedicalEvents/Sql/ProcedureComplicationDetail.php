<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class ProcedureComplicationDetail extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'procedure_id',
        'identifier_id',
    ];
}
