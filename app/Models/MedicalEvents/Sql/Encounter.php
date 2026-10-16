<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Contracts\SubmittedToEHealth;
use App\Enums\Person\EncounterStatus;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Encounter extends Model implements SubmittedToEHealth
{
    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'cancellation_reason_id',
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
        'preperson_id',
        'cancellation_reason_id',
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

    public function cancellationReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'cancellation_reason_id');
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

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    /**
     * eHealth jobs the signed encounter package was submitted with, the latest one telling how the last submission went.
     *
     * @return MorphMany
     */
    public function ehealthLinks(): MorphMany
    {
        return $this->morphMany(EhealthLink::class, 'linkable');
    }

    /**
     * Determine whether the episode of the encounter is managed by the legal entity the user works for.
     * Only an episode that is stored with a managing organization can be told apart, so an episode that
     * came from the short sync without one, and an episode that has not been synced at all, both pass.
     *
     * @return bool
     */
    public function belongsToCurrentLegalEntity(): bool
    {
        $episodeId = $this->episode?->value;

        if ($episodeId === null) {
            return true;
        }

        $episode = Episode::whereUuid($episodeId)->first();

        // Episodes are synced separately from encounters, so the episode may simply not be here yet
        if ($episode === null) {
            return true;
        }

        $managingOrganization = $episode->managingOrganization?->value;

        return $managingOrganization === null || $managingOrganization === legalEntity()->uuid;
    }

    /**
     * Filter encounters belonging to the given patient (person or preperson).
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $patient instanceof Preperson
            ? $query->wherePrepersonId($patient->id)
            : $query->wherePersonId($patient->id);
    }

    /**
     * Filter the patient's encounters of the current legal entity. Of the managing organizations the rule names,
     * only the episode's one is stored for an encounter.
     *
     * ABAC rule 2.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function forLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'episode',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Episode::forPatient($patient)->managedByLegalEntity()->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's encounters whose episode is managed by the current legal entity.
     *
     * ABAC rule 3.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function inEpisodeOfLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'episode',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Episode::forPatient($patient)->managedByLegalEntity()->pluck('uuid')
            )
        );
    }

    /**
     * Filter the patient's encounters originated by an episode managed by the current legal entity.
     *
     * ABAC rule 6.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function originatedInLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->forPatient($patient)->whereHas(
            'originEpisode',
            static fn (Builder $identifier): Builder => $identifier->whereIn(
                'value',
                Episode::forPatient($patient)->managedByLegalEntity()->pluck('uuid')
            )
        );
    }

    /**
     * Encounters of the patient the user may read: with access to all the patient's data (an active declaration or
     * approval) all of them, including the ones of the persons and prepersons merged into a person, otherwise only
     * the patient's own ones of the current legal entity or originated by its episode.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  bool  $hasPatientAccess
     * @return Builder
     */
    #[Scope]
    protected function readableFor(Builder $query, Person|Preperson $patient, bool $hasPatientAccess): Builder
    {
        if ($hasPatientAccess) {
            return $patient instanceof Person
                ? $patient->filterRecordsWithMerged($query)
                : $query->forPatient($patient);
        }

        return $query->where(
            static fn (Builder $encounter): Builder => $encounter->forLegalEntity($patient)
                ->orWhere(static fn (Builder $originated): Builder => $originated->originatedInLegalEntity($patient))
        );
    }

    /**
     * Filter the patient's encounters based on a referral issued under a care plan one of the user's employees has
     * an active read approval on.
     *
     * ABAC rule 14.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @param  User  $user
     * @param  LegalEntity  $legalEntity
     * @return Builder
     */
    #[Scope]
    protected function basedOnApprovedCarePlan(
        Builder $query,
        Person|Preperson $patient,
        User $user,
        LegalEntity $legalEntity
    ): Builder {
        if ($patient instanceof Preperson) {
            return $query->whereRaw('1 = 0');
        }

        $carePlanIds = CarePlan::wherePersonId($patient->id)
            ->whereKey(Approval::carePlanAccessGrantedTo($user, $legalEntity)->pluck('approvable_id'))
            ->pluck('uuid');

        $referralIds = ServiceRequestRequest::whereHas(
            'basedOn',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $carePlanIds)
        )->pluck('uuid');

        return $query->forPatient($patient)->whereHas(
            'incomingReferral',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $referralIds)
        );
    }

    /**
     * Filter the patient's encounters based on a referral issued under a care plan managed by the current legal entity.
     *
     * ABAC rule 17.
     *
     * @param  Builder  $query
     * @param  Person|Preperson  $patient
     * @return Builder
     */
    #[Scope]
    protected function basedOnCarePlanOfLegalEntity(Builder $query, Person|Preperson $patient): Builder
    {
        if ($patient instanceof Preperson) {
            return $query->whereRaw('1 = 0');
        }

        $carePlanIds = CarePlan::wherePersonId($patient->id)
            ->whereHas(
                'managingOrganization',
                static fn (Builder $identifier): Builder => $identifier->whereValue(legalEntity()->uuid)
            )
            ->pluck('uuid');

        $referralIds = ServiceRequestRequest::whereHas(
            'basedOn',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $carePlanIds)
        )->pluck('uuid');

        return $query->forPatient($patient)->whereHas(
            'incomingReferral',
            static fn (Builder $identifier): Builder => $identifier->whereIn('value', $referralIds)
        );
    }

    /**
     * Filter encounters belonging to the given episode, which is stored as an identifier holding its eHealth ID.
     *
     * @param  Builder  $query
     * @param  string  $episodeId
     * @return Builder
     */
    #[Scope]
    protected function forEpisode(Builder $query, string $episodeId): Builder
    {
        return $query->whereHas(
            'episode',
            static fn (Builder $identifier): Builder => $identifier->whereValue($episodeId)
        );
    }

    /**
     * Order by most recently updated in eHealth first, keeping records without a timestamp last. Drafts lead the list,
     * by their local update time, as they are not in eHealth yet.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function recentlyUpdatedFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [EncounterStatus::DRAFT->value])
            ->orderByRaw('CASE WHEN ehealth_updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ehealth_updated_at')
            ->orderByDesc('updated_at');
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
            'cancellationReason.coding',
            'performerSpeciality.coding',
            'visit.type.coding',
            'episode.type.coding',
            'incomingReferral.type.coding',
            'paperReferral',
            'originEpisode.type.coding',
            'performer.type.coding',
            'division.type.coding',
            'period',
            'reasons.coding',
            'actions.coding',
            'diagnoses.condition.type.coding',
            'diagnoses.role.coding',
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
