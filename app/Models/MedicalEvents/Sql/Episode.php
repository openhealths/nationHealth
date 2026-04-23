<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\EpisodeStatus;
use Eloquence\Behaviours\HasCamelCasing;
use App\Models\Person\Person;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Episode extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'encounter_id',
        'episode_type_id',
        'status',
        'name',
        'managing_organization_id',
        'care_manager_id',
        'status_reason_id',
        'closing_summary',
        'explanatory_letter',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'encounter_id',
        'episode_type_id',
        'managing_organization_id',
        'care_manager_id',
        'status_reason_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => EpisodeStatus::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    public function period(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'episode_type_id');
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function careManager(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'care_manager_id');
    }

    public function statusReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function currentDiagnoses(): HasMany
    {
        return $this->hasMany(EpisodeCurrentDiagnosis::class);
    }

    public function diagnosesHistory(): HasMany
    {
        return $this->hasMany(EpisodeDiagnosesHistory::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EpisodeStatusHistory::class);
    }

    #[Scope]
    protected function withRelationships(Builder $query): Builder
    {
        return $query->with([
            'type',
            'managingOrganization.type.coding',
            'careManager.type.coding',
            'statusReason.coding',
            'period',
            'currentDiagnoses.code.coding',
            'currentDiagnoses.condition.type.coding',
            'currentDiagnoses.role.coding',
            'diagnosesHistory.evidence.type.coding',
            'diagnosesHistory.diagnoses.condition.type.coding',
            'diagnosesHistory.diagnoses.code.coding',
            'diagnosesHistory.diagnoses.role.coding',
            'statusHistory.statusReason.coding',
        ]);
    }
}
