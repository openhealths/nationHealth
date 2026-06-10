<?php

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\MedicalEvents\Sql\Identifier;
use Illuminate\Database\Eloquent\Attributes\Scope;
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
        'created_by_id',
        'authorize_with',
        'authentication_method_id',
        'access_level',
        'is_verified',
        'expires_at'
    ];

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


    public function grantedResources(): HasMany
    {
        return $this->hasMany(ApprovalGrantedResource::class);
    }

    public function grantedResourceTypes(): HasMany
    {
        return $this->hasMany(ApprovalGrantedResourceType::class);
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
}
