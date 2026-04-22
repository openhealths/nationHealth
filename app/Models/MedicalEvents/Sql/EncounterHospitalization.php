<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncounterHospitalization extends Model
{
    protected $fillable = [
        'encounter_id',
        'pre_admission_identifier',
        'admit_source_id',
        're_admission_id',
        'destination_id',
        'discharge_disposition_id',
        'discharge_department_id'
    ];

    protected $hidden = [
        'id',
        'encounter_id',
        'admit_source_id',
        're_admission_id',
        'destination_id',
        'discharge_disposition_id',
        'discharge_department_id',
        'created_at',
        'updated_at'
    ];

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function admitSource(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'admit_source_id');
    }

    public function reAdmission(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 're_admission_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'destination_id');
    }

    public function dischargeDisposition(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'discharge_disposition_id');
    }

    public function dischargeDepartment(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'discharge_department_id');
    }
}
