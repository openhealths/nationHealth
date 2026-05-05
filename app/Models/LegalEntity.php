<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\User\Role;
use App\Enums\JobStatus;
use App\Models\Relations\Phone;
use App\Models\Relations\Address;
use App\Models\Employee\Employee;
use App\Casts\LegalEntityArchiveCast;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee\EmployeeRequest;
use Eloquence\Behaviours\HasCamelCasing;
use App\Models\Contracts\ContractRequest;
use Illuminate\Database\Eloquent\Builder;
use App\Casts\LegalEntityAccreditationCast;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LegalEntity extends Model
{
    use HasCamelCasing;

    public const string TYPE_MSP = 'MSP';
    public const string TYPE_MSP_LIMITED = 'MSP_LIMITED';
    public const string TYPE_MIS = 'MIS';
    public const string TYPE_NHS = 'NHS';
    public const string TYPE_PHARMACY = 'PHARMACY';
    public const string TYPE_EMERGENCY = 'EMERGENCY';
    public const string TYPE_OUTPATIENT = 'OUTPATIENT';
    public const string TYPE_PRIMARY_CARE = 'PRIMARY_CARE';
    public const string TYPE_MSP_PHARMACY = 'MSP_PHARMACY';

    public const string ENTITY_DIVISION = 'division_';
    public const string ENTITY_HEALTHCARE_SERVICE = 'hcs_';
    public const string ENTITY_EMPLOYEE = 'employee_';
    public const string ENTITY_EMPLOYEE_ROLE = 'employee_role_';
    public const string ENTITY_EMPLOYEE_REQUEST = 'employee_request_';
    public const string ENTITY_LICENSE = 'license_';
    public const string ENTITY_CONTRACT = 'contract_';
    public const string ENTITY_CONTRACT_REQUEST = 'contract_request_';
    public const string ENTITY_DECLARATION = 'declaration_';
    public const string ENTITY_DECLARATION_REQUEST = 'declaration_request_';
    public const string ENTITY_EQUIPMENT = 'equipment_';
    public const string ENTITY_EPISODE = 'episode_';
    public const string ENTITY_ENCOUNTER = 'encounter_';
    public const string ENTITY_CLINICAL_IMPRESSION = 'clinical_impression_';
    public const string ENTITY_IMMUNIZATION = 'immunization_';
    public const string ENTITY_OBSERVATION = 'observation_';
    public const string ENTITY_CONDITION = 'condition_';
    public const string ENTITY_DIAGNOSTIC_REPORT = 'diagnostic_report_';

    protected $fillable = [
        'uuid',
        'accreditation',
        'archive',
        'beneficiary',
        'edr',
        'edr_verified',
        'edrpou',
        'email',
        'inserted_at',
        'inserted_by',
        'is_active',
        'nhs_comment',
        'nhs_reviewed',
        'nhs_verified',
        'receiver_funds_code',
        'residence_address',
        'status',
        'updated_at',
        'updated_by',
        'website',
        'client_id',
        'client_secret',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $casts = [
        'accreditation' => LegalEntityAccreditationCast::class,
        'archive' => LegalEntityArchiveCast::class,
        'edr' => 'array',
        'inserted_at' => 'datetime',
        'updated_at' => 'datetime',
        'inserted_by' => 'string',
        'updated_by' => 'string',
        'contract_sync_status' => JobStatus::class,
        'contract_request_sync_status' => JobStatus::class,
        'episode_sync_status' => JobStatus::class,
        'encounter_sync_status' => JobStatus::class,
        'clinical_impression_sync_status' => JobStatus::class,
        'immunization_sync_status' => JobStatus::class,
        'observation_sync_status' => JobStatus::class,
    ];

    protected $attributes = [
        'is_active' => false,
    ];

    public null|object $owner;

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeRequests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }

    public function setAddressesAttribute($value)
    {
        $this->attributes['addresses'] = json_encode($value);
    }

    public function setKvedsAttribute($value)
    {
        $this->attributes['kveds'] = json_encode($value);
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(\App\Models\Contracts\Contract::class, 'legal_entity_id');
    }

    public function contractRequests(): HasMany
    {
        return $this->hasMany(ContractRequest::class, 'contractor_legal_entity_id', 'uuid');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function legators(): HasMany
    {
        return $this->hasMany(Legator::class);
    }

    /**
     * Relation to the reference LegalEntityTypes entry.
     *
     * @return BelongsTo
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_id');
    }

    /**
     * Get Owner Legal Entity
     *
     * @return object|null
     */
    public function getOwner(): ?object
    {
        return $this->employees()->whereEmployeeType(Role::OWNER)->first();
    }

    public function healthcareServices(): HasMany
    {
        return $this->hasMany(HealthcareService::class);
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(Revision::class, 'revisionable');
    }

    /**
     * Scope a query to get an Legal Entity depends on it's UUID
     */
    #[Scope]
    public function byUuid(Builder $query, string $legalEntityUUID): void
    {
        $query->where('uuid', $legalEntityUUID);
    }

    public function hasActivePrimaryLicense(): bool
    {
        return $this->licenses()->whereIsPrimary(true)->whereIsActive(true)->exists();
    }

    /**
     * Scope a query to get all Legal Entities with selected fields
     * Default fields are: id, uuid, edr, legal_entity_type_id
     *
     * @param  Builder  $query
     * @param  array  $fields
     * @return void
     */
    #[Scope]
    protected function listByFields(Builder $query, array $fields = []): void
    {
        if (empty($fields)) {
            $query->select(['id', 'uuid', 'status', 'edr', 'legal_entity_type_id'])->orderBy('id');
        } else {
            $query->select($fields)->orderBy('id');
        }
    }

    /**
     * Updates the status of a legal entity's (whole or partial) sync process.
     *
     * @param  JobStatus  $status  The new status to set for the legal entity's sync entity
     * @param  string  $entityType  Optional entity type specification, defaults to empty string
     * @return void
     */
    public function setEntityStatus(JobStatus $status, string $entityType = ''): void
    {
        $this->{$entityType . 'sync_status'} = $status->value;
        $this->save();
        $this->refresh();
    }

    /**
     * Get the status of a legal entity's sync process based on entity type.
     *
     * @param  string|null  $entityType  The type of legal entity's entity sync process to check
     * @return JobStatus|string|null The status of the sync process of entity or null if not found
     */
    public function getEntityStatus(?string $entityType = ''): JobStatus|string|null
    {
        return $this->{$entityType . 'sync_status'};
    }

    /**
     * Invalidate cached mapping for legal_entity_id -> legal_entity_type_id
     * immediately after the type changes, so permission scoping updates at once.
     */
    protected static function booted(): void
    {
        static::updated(function (LegalEntity $entity): void {
            if ($entity->wasChanged('legal_entity_type_id') || $entity->wasChanged('status')) {
                cache()->forget('le_type:' . $entity->getKey());
            }
        });
    }
}
