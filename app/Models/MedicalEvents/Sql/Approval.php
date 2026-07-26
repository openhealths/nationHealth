<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

    #[Scope]
    protected function isVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
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
}
