<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql\Medications;

use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Identifier;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationRequestRequest extends Model
{
    use HasCamelCasing;

    protected $table = 'medication_request_requests';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_EHEALTH = 'ehealth';

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

    public function intent(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'intent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'priority_id');
    }
}
