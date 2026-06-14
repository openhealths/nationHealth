<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
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
    ];

    protected $appends = ['granted_to_details'];

    /**
     * Get the parent approvable model (CarePlan, DiagnosticReport, etc.).
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Entity that was granted access.
     */
    public function grantedTo(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'granted_to_id');
    }

    public function identifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MedicalEvents\Sql\Identifier::class, 'granted_to_id');
    }

    public function grantedResources(): HasMany
    {
        return $this->hasMany(ApprovalGrantedResource::class);
    }

    public function grantedResourceTypes(): HasMany
    {
        return $this->hasMany(ApprovalGrantedResource::class);
    }

    public function getGrantedToDetailsAttribute(): array
    {
        $uuid = $this->identifier?->value;
        if (!$uuid) {
            return [
                'name' => '-',
                'description' => $this->granted_to_type ?? ''
            ];
        }

        if ($this->granted_to_type === 'employee') {
            $employee = \App\Models\Employee\Employee::where('uuid', $uuid)->with('party', 'specialities')->first();
            if ($employee) {
                $name = $employee->fullName;
                $specNames = [];
                
                try {
                    $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
                    $specialityTypeDict = $basics->byName('eHealth/SPECIALITY_TYPE')?->asCodeDescription()?->toArray() 
                        ?? $basics->byName('SPECIALITY_TYPE')?->asCodeDescription()?->toArray() 
                        ?? [];
                } catch (\Exception $e) {
                    $specialityTypeDict = [];
                }

                foreach ($employee->specialities as $spec) {
                    $specName = $specialityTypeDict[$spec->speciality] ?? $spec->speciality;
                    $specNames[] = $specName;
                }

                $specialization = implode(', ', array_unique(array_filter($specNames)));
                if (empty($specialization) && $employee->position) {
                    try {
                        $positionDict = $basics->byName('eHealth/POSITION')?->asCodeDescription()?->toArray() 
                            ?? $basics->byName('POSITION')?->asCodeDescription()?->toArray() 
                            ?? [];
                        $specialization = $positionDict[$employee->position] ?? $employee->position;
                    } catch (\Exception $e) {
                        $specialization = $employee->position;
                    }
                }

                return [
                    'name' => $name,
                    'description' => 'Співробітник' . ($specialization ? ' (' . $specialization . ')' : '')
                ];
            }
        } elseif ($this->granted_to_type === 'legal_entity') {
            $legalEntity = \App\Models\LegalEntity::where('uuid', $uuid)->first();
            if ($legalEntity) {
                return [
                    'name' => $legalEntity->name,
                    'description' => 'Заклад охорони здоров\'я (ЄДРПОУ: ' . ($legalEntity->edrpou ?? '-') . ')'
                ];
            }
        }

        return [
            'name' => $uuid,
            'description' => $this->granted_to_type ?? ''
        ];
    }
}
