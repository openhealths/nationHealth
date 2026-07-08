<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Models\EhealthLink;
use App\Models\Employee\Employee;
use App\Models\ApprovalGrantedResource;
use App\Models\ApprovalGrantedResourceTypes;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    protected $table = 'approvals';

    protected $fillable = [
        'uuid',
        'approvable_id',
        'approvable_type',
        'created_by_id',
        'granted_to_id',
        'granted_to_type',
        'granted_by_id',
        'authorize_with',
        'authentication_method_id',
        'reason_id',
        'status',
        'access_level',
        'is_verified',
        'expires_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * PHPDoc block for array shape returned by getGrantedToDetailsAttribute().
     *
     * @return array{name: string, description: string}
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
     * The Identifier record that represents who was granted access.
     * granted_to_id is a FK → identifiers.id; the Identifier::value holds the UUID.
     */
    public function grantedTo(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'granted_to_id');
    }

    /**
     * The Identifier record that created this approval request.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'created_by_id');
    }

    /**
     * The Identifier record encoding the reason for this approval.
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
        return $this->hasMany(ApprovalGrantedResourceTypes::class);
    }

    /**
     * Async eHealth job links attached to this approval.
     */
    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
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
                'description' => $this->granted_to_type ?? '',
            ];
        }

        if ($this->granted_to_type === 'employee') {
            $employee = Employee::where('uuid', $uuid)->with('party', 'specialities')->first();

            if ($employee) {
                $specNames = [];

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
                        $positionDict = $basics->byName('eHealth/POSITION')?->asCodeDescription()?->toArray()
                            ?? $basics->byName('POSITION')?->asCodeDescription()?->toArray()
                            ?? [];
                        $specialization = $positionDict[$employee->position] ?? $employee->position;
                    } catch (\Exception) {
                        $specialization = $employee->position;
                    }
                }

                return [
                    'name' => $employee->fullName,
                    'description' => 'Співробітник' . ($specialization ? ' (' . $specialization . ')' : ''),
                ];
            }
        }

        if ($this->granted_to_type === 'legal_entity') {
            $legalEntity = \App\Models\LegalEntity::where('uuid', $uuid)->first();

            if ($legalEntity) {
                return [
                    'name' => $legalEntity->name,
                    'description' => 'Заклад охорони здоров\'я (ЄДРПОУ: ' . ($legalEntity->edrpou ?? '-') . ')',
                ];
            }
        }

        return [
            'name' => $uuid,
            'description' => $this->granted_to_type ?? '',
        ];
    }
}
