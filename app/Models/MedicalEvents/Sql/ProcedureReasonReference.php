<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;

class ProcedureReasonReference extends Model
{
    protected $fillable = [
        'procedure_id',
        'identifier_id'
    ];
}
