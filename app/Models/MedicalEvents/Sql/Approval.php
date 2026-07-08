<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Models\EhealthLink;
use App\Models\Employee\Employee;
use App\Services\Dictionary\DictionaryManager;
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
            'grantedResourceTypes.codeableConcept.coding',
        ]);
    }

    #[Scope]
    protected function isAlive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now());
    }

    #[Scope]
    protected function isVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    #[Scope]
    protected function getByModel(Builder $query, int $personId, string $modelClass): Builder
    {
        return $query->whereHas('approvable', function (Builder $query) use ($personId, $modelClass) {
            $query
                ->where('approvable_type', $modelClass)
                ->where('approvable_id', $personId);
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

        if (! $uuid) {
            return [
                'name' => '-',
                'description' => $this->granted_to_type ?? '',
            ];
        }

        if ($this->granted_to_type === 'employee') {
            $employee = Employee::where('uuid', $uuid)->with('party', 'specialities')->first();

            if ($employee) {
                $specNames = [];
                $basics = null;

                try {
                    $basics = app(DictionaryManager::class)->basics();
                    $specialityDict = $basics->byName('eHealth/SPECIALITY_TYPE')?->asCodeDescription()?->toArray()
                        ?? $basics->byName('SPECIALITY_TYPE')?->asCodeDescription()?->toArray()
                        ?? [];
                } catch (\Exception) {
                    $specialityDict = [];
                }

                foreach ($employee->specialities as $spec) {
                    $specNames[] = $specialityDict[$spec->speciality] ?? $spec->speciality;
                }

                $specialization = implode(', ', array_unique(array_filter($specNames)));

                if (empty($specialization) && $employee->position) {
                    try {
                        $positionDict = $basics?->byName('eHealth/POSITION')?->asCodeDescription()?->toArray()
                            ?? $basics?->byName('POSITION')?->asCodeDescription()?->toArray()
                            ?? [];
                        $specialization = $positionDict[$employee->position] ?? $employee->position;
                    } catch (\Exception) {
                        $specialization = $employee->position;
                    }
                }

                return [
                    'name' => $employee->fullName,
                    'description' => 'Співробітник'.($specialization ? ' ('.$specialization.')' : ''),
                ];
            }
        }

        if ($this->granted_to_type === 'legal_entity') {
            $legalEntity = \App\Models\LegalEntity::where('uuid', $uuid)->first();

            if ($legalEntity) {
                return [
                    'name' => $legalEntity->name,
                    'description' => 'Заклад охорони здоров\'я (ЄДРПОУ: '.($legalEntity->edrpou ?? '-').')',
                ];
            }
        }

        return [
            'name' => $uuid,
            'description' => $this->granted_to_type ?? '',
        ];
    }
}
