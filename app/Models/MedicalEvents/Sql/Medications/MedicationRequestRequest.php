<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql\Medications;

use App\Enums\Medication\RequestResourceType;

use App\Enums\Medication\RequestSource;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Period;
use App\Models\MedicalEvents\Sql\Quantity;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MedicationRequestRequest extends Model
{
    use HasCamelCasing;

    protected $table = 'medication_request_requests';

    public const SOURCE_LOCAL = RequestSource::LOCAL->value;

    public const SOURCE_EHEALTH = RequestSource::EHEALTH->value;

    public const TYPE_REQUEST = RequestResourceType::REQUEST->value;

    public const TYPE_PRESCRIPTION = RequestResourceType::PRESCRIPTION->value;

    protected $attributes = [
        'source' => self::SOURCE_LOCAL,
        'resource_type' => self::TYPE_REQUEST,
    ];

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
        'resource_type',
        'details_synced_at',
        'signed_prescription_pending',
        'authored_on',
        'dispense_valid_from',
        'dispense_valid_to',
        'rejected_at',
        'rejected_by_uuid',
        'blocked_to',
        'reject_reason',
        'reject_reason_code',
        'block_reason',
        'block_reason_code',
        'quantity_id',
        'container_quantity_id',

    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'medication_qty' => 'decimal:10',
        'details_synced_at' => 'datetime',
        'signed_prescription_pending' => 'boolean',

        'ehealth_payload' => 'array',
    ];

    public function quantityQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'quantity_id');
    }

    public function containerQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'container_quantity_id');
    }

    public function references(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'medication_request_references', 'medication_request_id', 'identifier_id')
            ->withPivot(['field', 'position'])->orderByPivot('position');
    }

    public function period(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

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
