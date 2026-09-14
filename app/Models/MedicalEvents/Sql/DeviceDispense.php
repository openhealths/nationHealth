<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\DeviceDispense\Status;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeviceDispense extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'based_on_id',
        'part_of_id',
        'performer_id',
        'location_id',
        'when_handed_over',
        'quantity',
        'device_code_id',
        'device_definition_id',
        'context_id',
        'explanatory_letter',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $casts = [
        'status' => Status::class,
        'quantity' => 'integer',
        'when_handed_over' => EHealthTimestampCast::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'based_on_id',
        'part_of_id',
        'performer_id',
        'location_id',
        'device_code_id',
        'device_definition_id',
        'context_id',
        'created_at',
        'updated_at'
    ];

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    /**
     * The device request the dispense is issued against, absent when the devices were handed over without one.
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function partOf(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'part_of_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'location_id');
    }

    /**
     * The encounter the dispense is recorded within.
     */
    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    /**
     * The classification type of the device handed over, set when the dispense names a type.
     */
    public function deviceCode(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'device_code_id');
    }

    /**
     * The device definition handed over, set when the dispense names a model or a brand.
     */
    public function deviceDefinition(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'device_definition_id');
    }

    public function supportingInfo(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'device_dispense_supporting_info');
    }

    /**
     * Scope to eager load all device dispense relationships.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'basedOn.type.coding',
            'partOf.type.coding',
            'performer.type.coding',
            'location.type.coding',
            'context.type.coding',
            'deviceCode.coding',
            'deviceDefinition.type.coding',
            'supportingInfo.type.coding'
        ]);
    }

    /**
     * Filter dispenses belonging to the given patient (person or preperson).
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
     * Filter dispenses recorded within the given encounter, which is stored as the context identifier.
     *
     * @param  Builder  $query
     * @param  string  $encounterId
     * @return Builder
     */
    #[Scope]
    protected function forEncounter(Builder $query, string $encounterId): Builder
    {
        return $query->whereHas(
            'context',
            static fn (Builder $identifier): Builder => $identifier->whereValue($encounterId)
        );
    }
}
