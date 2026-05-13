<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ReorganizationEmployeeDeclaration extends Pivot
{
    protected $table = 'reorganization_employee_declarations';

    protected $fillable = [
        'legal_entity_id',
        'legal_entity_uuid',
        'employee_id',
        'employee_uuid',
        'party_id',
        'party_uuid',
        'person_id',
        'person_uuid',
        'declaration_id',
        'declaration_uuid',
        'declaration_number',
        'authorize_with',
        'updated_at'
    ];
}
