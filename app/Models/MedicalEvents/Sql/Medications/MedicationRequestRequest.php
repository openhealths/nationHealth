<?php

namespace App\Models\MedicalEvents\Sql\Medications;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationRequestRequest extends Model
{
    use HasCamelCasing;

    protected $table = 'medication_request_requests';

    /**
     * Add real attributes you allow for mass assignment.
     */
    protected $fillable = [
        'uuid',
        'employee_id',
        'person_id',
        'division_id',
        'status',
        'request_number',
        'started_at',
        'ended_at',
        'medication_id',
        'medication_qty',
        'medication_program_id',
        'intent',
        'category',
        'based_on_id',
        'context_id',
        'priority',
        'prior_prescription_id',
        'container_dosage',
        'note',
        'inform_with'
    ];

    public function dosageInstructions(): HasMany
    {
        return $this->hasMany(DosageInstruction::class);
    }
}
