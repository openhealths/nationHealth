<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\ImmunizationStatus;
use App\Models\Person\Person;
use App\Models\Preperson;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class Immunization extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'not_given',
        'vaccine_code_id',
        'context_id',
        'date',
        'primary_source',
        'performer_id',
        'report_origin_id',
        'manufacturer',
        'lot_number',
        'expiration_date',
        'explanatory_letter',
        'site_id',
        'route_id',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $casts = [
        'date' => EHealthTimestampCast::class,
        'status' => ImmunizationStatus::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'vaccine_code_id',
        'context_id',
        'performer_id',
        'report_origin_id',
        'site_id',
        'route_id',
        'created_at',
        'updated_at'
    ];

    protected $appends = [
        'explanation',
        'time'
    ];

    protected function time(): Attribute
    {
        return Attribute::make(
            get: fn () => CarbonImmutable::parse($this->date)->format('H:i')
        );
    }

    /**
     * Scope to eager load all immunization relationships.
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'vaccineCode.coding',
            'context.type.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'site.coding',
            'route.coding',
            'doseQuantity',
            'vaccinationProtocols.authority.coding',
            'vaccinationProtocols.targetDiseases.coding',
            'reactions.detail.type.coding',
            'explanations.reasons.coding',
            'explanations.reasonsNotGiven.coding'
        ]);
    }

    /**
     * Filter immunizations belonging to the given patient (person or preperson).
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
     * Order by most recently updated in eHealth first, keeping records without a timestamp last.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function recentlyUpdatedFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN ehealth_updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ehealth_updated_at');
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function vaccineCode(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'vaccine_code_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'site_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'route_id');
    }

    public function doseQuantity(): HasOne
    {
        return $this->hasOne(ImmunizationDoseQuantity::class, 'immunization_id');
    }

    public function explanations(): HasMany
    {
        return $this->hasMany(ImmunizationExplanation::class, 'immunization_id');
    }

    protected function explanation(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                'reasons' => $this->explanations()
                    ->with(['reasons.coding'])
                    ->get()
                    ->pluck('reasons')
                    ->filter()
                    ?->toArray() ?: [],
                'reasonsNotGiven' => $this->explanations()
                    ->with(['reasonsNotGiven.coding'])
                    ->get()
                    ->pluck('reasonsNotGiven')
                    ->filter()
                    ?->toArray() ?: []
            ]
        );
    }

    public function vaccinationProtocols(): HasMany
    {
        return $this->hasMany(ImmunizationVaccinationProtocol::class, 'immunization_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ImmunizationReaction::class, 'immunization_id');
    }
}
