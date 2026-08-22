<?php

declare(strict_types=1);

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
        'intent_id',
        'category_id',
        'based_on_id',
        'context_id',
        'priority_id',
        'prior_prescription_id',
        'container_dosage',
        'note',
        'inform_with',
        'ehealth_payload',
        'source',
    ];

    public const SOURCE_LOCAL = 'local';
    public const SOURCE_EHEALTH = 'ehealth';

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'medication_qty' => 'decimal:2',
        'ehealth_payload' => 'array',
    ];

    public function dosageInstructions(): HasMany
    {
        return $this->hasMany(DosageInstruction::class);
    }

    public function intent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\MedicalEvents\Sql\Coding::class, 'intent_id');
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\MedicalEvents\Sql\CodeableConcept::class, 'category_id');
    }

    public function basedOn(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\MedicalEvents\Sql\Identifier::class, 'based_on_id');
    }

    public function context(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\MedicalEvents\Sql\Identifier::class, 'context_id');
    }

    public function priority(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\MedicalEvents\Sql\CodeableConcept::class, 'priority_id');
    }
}
