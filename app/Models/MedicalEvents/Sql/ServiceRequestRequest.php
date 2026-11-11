<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\UtcDateTimeCast;
use App\Contracts\SubmittedToEHealth;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\Employee\Employee;

use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ServiceRequestRequest extends Model implements SubmittedToEHealth
{
    use HasCamelCasing;

    protected $table = 'service_request_requests';

    protected $fillable = [
        'uuid',
        'employee_id',
        'person_id',
        'preperson_id',
        'division_id',
        'status',
        'request_number',
        'started_at',
        'ended_at',
        'service_id',
        'quantity',
        'program_id',
        'intent_id',
        'category_id',
        'based_on_id',
        'context_id',
        'priority_id',
        'note',
        'patient_instruction',
        'reason_reference',
        'inform_with',
        'supporting_info',
        'performer_id',
        'location_reference_id',
        'performer_type_id',

        'details_synced_at',
        'clinical_status',
        'program_processing_status',
        'authored_on',
        'explanatory_letter',
        'status_reason_id',
        'code_identifier_id',
        'program_identifier_id',
        'requester_employee_id',
        'requester_legal_entity_id',
        'subject_identifier_id',
        'used_by_employee_id',
        'used_by_legal_entity_id',
        'completed_with_id',
        'status_history',
        'expiration_date',
        'quantity_id',
        'remaining_quantity_id',
        'occurrence_date_time',
    ];

    protected $casts = [
        'started_at' => UtcDateTimeCast::class,
        'ended_at' => UtcDateTimeCast::class,
        'quantity' => 'decimal:10',

        'details_synced_at' => 'datetime',
        'supporting_info' => 'array',
        'reason_reference' => 'array',
        'status_history' => 'array',
        'expiration_date' => UtcDateTimeCast::class,
    ];

    public function statusReasonConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

    public function codeReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'code_identifier_id');
    }
    public function programReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'program_identifier_id');
    }
    public function requesterEmployee(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'requester_employee_id');
    }
    public function requesterLegalEntity(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'requester_legal_entity_id');
    }
    public function subjectReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'subject_identifier_id');
    }

    public function usedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'used_by_employee_id');
    }

    public function usedByLegalEntity(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'used_by_legal_entity_id');
    }

    public function completedWith(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'completed_with_id');
    }
    public function quantityQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'quantity_id');
    }
    public function remainingQuantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'remaining_quantity_id');
    }
    public function occurrencePeriod(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    private function referenceCollection(string $collection): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'service_request_references', 'service_request_id', 'identifier_id')->withPivotValue('collection', $collection)->withPivot('position')->orderByPivot('position');
    }
    public function basedOnReferences(): BelongsToMany
    {
        return $this->referenceCollection('basedOn');
    }
    public function supportingInfoReferences(): BelongsToMany
    {
        return $this->referenceCollection('supportingInfo');
    }
    public function reasonReferences(): BelongsToMany
    {
        return $this->referenceCollection('reasonReference');
    }
    public function permittedReferences(): BelongsToMany
    {
        return $this->referenceCollection('permittedResources');
    }
    public function specimenReferences(): BelongsToMany
    {
        return $this->referenceCollection('specimens');
    }
    public function focusReferences(): BelongsToMany
    {
        return $this->referenceCollection('focus');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $patient instanceof Preperson
            ? $query->wherePrepersonId($patient->id)->whereNull('person_id')
            : $query->wherePersonId($patient->id)->whereNull('preperson_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'intent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function locationReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'location_reference_id');
    }

    public function performerType(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'performer_type_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'priority_id');
    }

    /**
     * Filter referrals issued under a care plan of the patient one of the user's employees has an active read approval
     * on.
     *
     * ABAC rule 14.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function basedOnApprovedCarePlan(
        Builder $query,
        Person|Preperson $patient,
        User $user,
        LegalEntity $legalEntity
    ): Builder {
        if ($patient instanceof Preperson) {
            return $query->whereRaw('1 = 0');
        }

        $carePlanIds = CarePlan::wherePersonId($patient->id)
            ->whereKey(Approval::carePlanAccessGrantedTo($user, $legalEntity)->pluck('approvable_id'))
            ->pluck('uuid');

        return $query->where(static function (Builder $scope) use ($carePlanIds): void {
            $scope->whereHas('basedOnReferences', static fn (Builder $identifier): Builder => $identifier->whereIn('value', $carePlanIds)->whereHas('type.coding', static fn (Builder $coding): Builder => $coding->whereCode('care_plan')))
                ->orWhere(static fn (Builder $legacy): Builder => $legacy->whereNull('details_synced_at')->whereHas('basedOn', static fn (Builder $identifier): Builder => $identifier->whereIn('value', $carePlanIds)));
        });
    }

    /**
     * Filter referrals issued under a care plan of the patient managed by the current legal entity.
     *
     * ABAC rule 17.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function basedOnCarePlanOfLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        if ($patient instanceof Preperson) {
            return $query->whereRaw('1 = 0');
        }

        $carePlanIds = CarePlan::wherePersonId($patient->id)
            ->whereHas(
                'managingOrganization',
                static fn (Builder $identifier): Builder => $identifier->whereValue(legalEntity()->uuid)
            )
            ->pluck('uuid');

        return $query->where(static function (Builder $scope) use ($carePlanIds): void {
            $scope->whereHas('basedOnReferences', static fn (Builder $identifier): Builder => $identifier->whereIn('value', $carePlanIds)->whereHas('type.coding', static fn (Builder $coding): Builder => $coding->whereCode('care_plan')))
                ->orWhere(static fn (Builder $legacy): Builder => $legacy->whereNull('details_synced_at')->whereHas('basedOn', static fn (Builder $identifier): Builder => $identifier->whereIn('value', $carePlanIds)));
        });
    }

    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
    }
}
