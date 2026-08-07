<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql\Medications;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DosageInstruction extends Model
{
    use HasCamelCasing;

    protected $table = 'dosage_instructions';

    protected $fillable = [
        'medication_request_request_id',
        'medication_request_id',
        'sequence',
        'text',
        'additional_instruction_id',
        'patient_instruction',
        'timing',
        'as_needed_boolean',
        'site_id',
        'route',
        'method',
        'dose_and_rate',
        'max_dose_per_period',
        'max_dose_per_administration',
        'max_dose_per_lifetime'
    ];

    public function medicationRequestRequest(): BelongsTo
    {
        return $this->belongsTo(MedicationRequestRequest::class);
    }

    public function doseRate(): HasMany
    {
        return $this->hasMany(DoseRate::class);
    }
}
