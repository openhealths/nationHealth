<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Person\EncounterStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Encounter extends Model
{
    protected $fillable = [
        'uuid',
        'person_id',
        'status',
        'visit_id',
        'episode_id',
        'class_id',
        'type_id',
        'priority_id',
        'performer_id',
        'performer_speciality_id',
        'division_id'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'visit_id',
        'episode_id',
        'class_id',
        'type_id',
        'priority_id',
        'performer_id',
        'performer_speciality_id',
        'division_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = ['status' => EncounterStatus::class];

    public function period(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'visit_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'episode_id');
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'class_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'priority_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function reasons(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'encounter_reasons');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnose::class)->with(['condition.type.coding', 'role.coding']);
    }

    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'encounter_actions');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'division_id');
    }

    public function performerSpeciality(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'performer_speciality_id');
    }

    /**
     * Scope to load only sync-related relationships.
     */
    #[Scope]
    protected function withSyncRelationships(Builder $query): Builder
    {
        return $query->with([
            'class',
            'type.coding',
            'performerSpeciality.coding',
            'episode.type.coding'
        ]);
    }

    /**
     * Scope to filter by person ID and exclude specific UUIDs.
     * Only includes encounters with UUIDs (from API), not local ones.
     */
    #[Scope]
    protected function orphanedForPerson(Builder $query, int $personId, array $excludeUuids): Builder
    {
        return $query->where('person_id', $personId)
            ->whereNotNull('uuid')
            ->whereNotIn('uuid', $excludeUuids);
    }
}
