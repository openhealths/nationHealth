<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\EncounterStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Encounter extends Model
{
    protected $fillable = [
        'uuid',
        'person_id',
        'status',
        'cancellation_reason',
        'explanatory_letter',
        'prescriptions',
        'visit_id',
        'episode_id',
        'class_id',
        'type_id',
        'priority_id',
        'performer_id',
        'performer_speciality_id',
        'division_id',
        'incoming_referral_id',
        'origin_episode_id',
        'ehealth_inserted_at',
        'ehealth_updated_at'
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
        'incoming_referral_id',
        'origin_episode_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => EncounterStatus::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    public function period(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function paperReferral(): MorphOne
    {
        return $this->morphOne(PaperReferral::class, 'paper_referralable');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'visit_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'episode_id');
    }

    public function incomingReferral(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'incoming_referral_id');
    }

    public function originEpisode(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'origin_episode_id');
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

    public function performerSpeciality(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'performer_speciality_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'division_id');
    }

    public function reasons(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'encounter_reasons');
    }

    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(CodeableConcept::class, 'encounter_actions');
    }

    public function actionReferences(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'encounter_action_references');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'encounter_participants');
    }

    public function supportingInfo(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'encounter_supporting_info');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnose::class)->with(['condition.type.coding', 'role.coding']);
    }

    public function hospitalization(): HasOne
    {
        return $this->hasOne(EncounterHospitalization::class);
    }

    /**
     * Scope to load only sync-related relationships.
     */
    #[Scope]
    protected function withRelationships(Builder $query): Builder
    {
        return $query->with([
            'class',
            'type.coding',
            'priority.coding',
            'performerSpeciality.coding',
            'visit.type.coding',
            'episode.type.coding',
            'incomingReferral.type.coding',
            'originEpisode.type.coding',
            'performer.type.coding',
            'division.type.coding',
            'period',
            'reasons.coding',
            'actions.coding',
            'actionReferences.type.coding',
            'participants.type.coding',
            'supportingInfo.type.coding',
            'hospitalization.admitSource',
            'hospitalization.reAdmission',
            'hospitalization.destination.type.coding',
            'hospitalization.dischargeDisposition',
            'hospitalization.dischargeDepartment'
        ]);
    }
}
