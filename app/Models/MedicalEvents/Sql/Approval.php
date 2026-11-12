<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Person\ApprovalStatus;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ApprovalGrantedResource;
use App\Models\MedicalEvents\Sql\ApprovalGrantedResourceType;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    use HasCamelCasing;

    protected $table = 'approvals';

    protected $fillable = [
        'uuid',
        'approvable_id',
        'approvable_type',
        'granted_to_id',
        'granted_to_type',
        'granted_by_id',
        'status',
        'reason_id',
        'created_by_id',
        'authorize_with',
        'authentication_method_id',
        'access_level',
        'is_verified',
        'expires_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['granted_to_details'];
    /**
     * Get the parent approvable model (CarePlan, DiagnosticReport, etc.).
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Entity that has granted access.
     */
    public function grantedTo(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'granted_to_id');
    }

    /**
     * Entity that granted access.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'created_by_id');
    }

    /**
     * Entity that granted access.
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'reason_id');
    }

    /**
     * The employee who granted this approval.
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'granted_by_id');
    }
    public function grantedResources(): HasMany
    {
        return $this->hasMany(ApprovalGrantedResource::class);
    }

    public function grantedResourceTypes(): HasMany
    {
        return $this->hasMany(ApprovalGrantedResourceType::class);
    }

    public function grantedResourceIdentifiers(): HasManyThrough
    {
        return $this->hasManyThrough(
            Identifier::class,
            ApprovalGrantedResource::class,
            'approval_id',   // FK on approval_granted_resources
            'id',            // FK on identifiers
            'id',            // local key on approvals
            'granted_to_id'  // local key on approval_granted_resources
        );
    }

    public function grantedResourceTypesIdentifiers(): HasManyThrough
    {
        return $this->hasManyThrough(
            Identifier::class,
            ApprovalGrantedResourceType::class,
            'approval_id',   // FK on approval_granted_resource_types
            'id',            // FK on identifiers
            'id',            // local key on approvals
            'codeable_concept_id'  // local key on approval_granted_resource_types
        );
    }

    /**
     * Async eHealth job links attached to this approval.
     */
    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
    }

    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'grantedTo.type.coding',
            'createdBy.type.coding',
            'reason.type.coding',
            'grantedResources.grantedTo.type.coding',
            'grantedResourceIdentifiers.type.coding',
            'grantedResourceTypes.codeableConcept.coding',
        ]);
    }

    /**
     * Filter approvals that grant write access to the resource with the given eHealth ID.
     *
     * @param  Builder  $query
     * @param  string  $resourceId
     * @return Builder
     */
    #[Scope]
    protected function grantingWriteAccessTo(Builder $query, string $resourceId): Builder
    {
        return $query->whereAccessLevel('write')
            ->whereHas(
                'grantedResources.grantedTo',
                static fn (Builder $identifier): Builder => $identifier->whereValue($resourceId)
            );
    }

    #[Scope]
    protected function isAlive(Builder $query, Model $model): Builder
    {
        return $query
            ->where('approvable_type', $model::class)
            ->where('approvable_id', $model->getKey())
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Filter verified approvals.
     *
     * Read the flag with getAttribute('is_verified'): the camelCase key isVerified
     * collides with this scope during Eloquent attribute resolution.
     * Keep the shared scope name and use snake_case for this attribute read.
     */
    #[Scope]
    protected function isVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * Filter approvals that are active, verified and not expired yet.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereStatus(ApprovalStatus::ACTIVE)
            ->isVerified()
            ->whereFuture('expires_at');
    }

    /**
     * Active approvals on the patient's data granted to one of the user's employees in the legal entity, which open
     * all the patient's data.
     *
     * ABAC rule 4.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  Person|Preperson  $patient
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function accessGrantedTo(Builder $query, User $user, Person|Preperson $patient, LegalEntity $legalEntity): Builder
    {
        return $query->whereMorphedTo('approvable', $patient)
            ->active()
            ->grantedToEmployees($user, $legalEntity);
    }

    /**
     * Active approvals on a type of the patient's records granted to one of the user's employees in the legal entity,
     * which open all the patient's records of that type.
     *
     * ABAC rule 16.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  Person|Preperson  $patient
     * @param  string  $resourceType
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function resourceTypeAccessGrantedTo(
        Builder $query,
        User $user,
        Person|Preperson $patient,
        string $resourceType,
        LegalEntity $legalEntity
    ): Builder {
        return $query->whereMorphedTo('approvable', $patient)
            ->when($resourceType === CarePlan::RESOURCE_TYPE, static fn (Builder $consent): Builder => $consent->verifiedUnexpired(), static fn (Builder $consent): Builder => $consent->active())
            ->whereHas(
                'grantedResourceTypes.codeableConcept.coding',
                static fn (Builder $coding): Builder => $coding->whereCode($resourceType)
            )
            ->grantedToEmployees($user, $legalEntity);
    }

    /**
     * Active approvals on the resource granted to one of the user's employees in the legal entity or to the legal
     * entity itself, which open that resource.
     *
     * ABAC rules 5 and 11.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  Model  $resource
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function resourceAccessGrantedTo(Builder $query, User $user, Model $resource, LegalEntity $legalEntity): Builder
    {
        return $query->whereMorphedTo('approvable', $resource)
            ->when($resource instanceof CarePlan, static fn (Builder $consent): Builder => $consent->verifiedUnexpired(), static fn (Builder $consent): Builder => $consent->active())
            ->grantedToEmployeesOrLegalEntity($user, $legalEntity);
    }

    /**
     * Active approvals on diagnostic reports granted to one of the user's employees in the legal entity or to the legal
     * entity itself, which open those reports.
     *
     * ABAC rule 11.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function diagnosticReportAccessGrantedTo(Builder $query, User $user, LegalEntity $legalEntity): Builder
    {
        return $query->whereApprovableType(DiagnosticReport::class)
            ->active()
            ->grantedToEmployeesOrLegalEntity($user, $legalEntity);
    }

    /**
     * Active read approvals on care plans granted by the patient to one of the user's employees in the legal entity,
     * which open the records based on those care plans.
     *
     * ABAC rule 14.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function carePlanAccessGrantedTo(Builder $query, User $user, LegalEntity $legalEntity): Builder
    {
        return $query->whereApprovableType(CarePlan::class)
            ->verifiedUnexpired()
            ->whereAccessLevel('read')
            ->grantedToEmployees($user, $legalEntity);
    }

    /**
     * Active verified approvals on the procedure granted to one of the user's employees in the legal entity, which
     * open that procedure.
     *
     * ABAC rule 15.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  Procedure  $procedure
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function procedureAccessGrantedTo(
        Builder $query,
        User $user,
        Procedure $procedure,
        LegalEntity $legalEntity
    ): Builder {
        return $query->whereHas(
            'grantedResources.grantedTo',
            static fn (Builder $identifier): Builder => $identifier->whereValue($procedure->uuid)
        )
            ->active()
            ->grantedToEmployees($user, $legalEntity);
    }

    /**
     * Filter approvals granted to one of the user's employees in the legal entity.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function grantedToEmployees(Builder $query, User $user, LegalEntity $legalEntity): Builder
    {
        return $query->whereGrantedToType('employee')
            ->whereHas(
                'grantedTo',
                static fn (Builder $identifier): Builder => $identifier->whereIn(
                    'value',
                    Employee::forUserInLegalEntity($user, $legalEntity)->pluck('uuid')
                )
            );
    }

    /**
     * Filter approvals granted to one of the user's employees in the legal entity or to the legal entity itself.
     *
     * @param  Builder  $query
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function grantedToEmployeesOrLegalEntity(Builder $query, User $user, LegalEntity $legalEntity): Builder
    {
        return $query->where(
            static fn (Builder $approval): Builder => $approval
                ->grantedToEmployees($user, $legalEntity)
                ->orWhere(
                    static fn (Builder $toLegalEntity): Builder => $toLegalEntity
                        ->whereGrantedToType('legal_entity')
                        ->whereHas(
                            'grantedTo',
                            static fn (Builder $identifier): Builder => $identifier->whereValue($legalEntity->uuid)
                        )
                )
        );
    }

    #[Scope]
    protected function getByModel(Builder $query, Model $model): Builder
    {
        return $query->whereHas('approvable', function (Builder $query) use ($model) {
            $query
                ->where('approvable_type', $model::class)
                ->where('approvable_id', $model->getKey());
        });
    }

    /**
     * Resolve human-readable display for the granted_to entity.
     *
     * @return array{name: string, description: string}
     */
    public function getGrantedToDetailsAttribute(): array
    {
        $uuid = $this->grantedTo?->value;

        if (!$uuid) {
            return [
                'name' => '-',
                'description' => __('approval.recipient_types.'.(in_array($this->grantedToType, ['employee', 'legal_entity'], true) ? $this->grantedToType : 'unknown')),
            ];
        }

        if ($this->grantedToType === 'employee') {
            $employee = Employee::where('uuid', $uuid)->with('party', 'specialities')->first();

            if ($employee) {
                $specialization = implode(', ', $employee->specialityLabels());

                return [
                    'name' => $employee->fullName,
                    'description' => $employee->positionLabel().($specialization ? ' ('.$specialization.')' : ''),
                ];
            }
        }

        if ($this->grantedToType === 'legal_entity') {
            $legalEntity = LegalEntity::where('uuid', $uuid)->first();

            if ($legalEntity) {
                return [
                    'name' => $legalEntity->name,
                    'description' => __('approval.legal_entity_description', ['edrpou' => $legalEntity->edrpou ?? '-']),
                ];
            }
        }

        return [
            'name' => $uuid,
            'description' => __('approval.recipient_types.'.(in_array($this->grantedToType, ['employee', 'legal_entity'], true) ? $this->grantedToType : 'unknown')),
        ];
    }
    /** Whether this consent belongs exclusively to Care Plans, including a pending patient request. */
    public function isCarePlanConsent(): bool
    {
        if ($this->approvableType === CarePlan::class) {
            return true;
        }
        if ($this->approvableType !== Person::class) {
            return false;
        }
        $this->loadMissing(['grantedResourceIdentifiers.type.coding', 'grantedResourceTypes.codeableConcept.coding', 'ehealthLinks.job']);
        $resources = $this->grantedResourceIdentifiers;
        $types = $this->grantedResourceTypes;
        $onlyResources = $resources->every(static fn (Identifier $identifier): bool => $identifier->type->first()?->coding->contains('code', CarePlan::RESOURCE_TYPE) === true);
        $onlyTypes = $types->every(static fn (ApprovalGrantedResourceType $type): bool => $type->codeableConcept?->coding->contains('code', CarePlan::RESOURCE_TYPE) === true);
        if ($resources->isNotEmpty() || $types->isNotEmpty()) {
            return $onlyResources && $onlyTypes;
        }
        if ($this->getAttribute('is_verified')) {
            return false;
        }
        foreach ($this->ehealthLinks->sortByDesc('id') as $link) {
            $requested = data_get($link->job?->request_data, 'resources');
            if (is_array($requested) && $requested !== []) {
                return collect($requested)->every(static fn (array $resource): bool => data_get($resource, 'identifier.type.coding.0.code') === CarePlan::RESOURCE_TYPE);
            }
        }

        return false;
    }

    public function hasVerifiedAccess(): bool
    {
        return $this->getAttribute('is_verified') && $this->expiresAt?->isFuture() === true;
    }

    #[Scope]
    protected function verifiedUnexpired(Builder $query): Builder
    {
        return $query->isVerified()->whereFuture('expires_at');
    }
}
