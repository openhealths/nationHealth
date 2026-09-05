<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Device\Status;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'status',
        'type_id',
        'model_number',
        'lot_number',
        'manufacturer',
        'serial_number',
        'manufacture_date',
        'expiration_date',
        'note',
        'primary_source',
        'report_origin_id',
        'context_id',
        'recorder_id',
        'definition_id',
        'parent_id'
    ];

    protected $casts = [
        'status' => Status::class,
        'manufacture_date' => EHealthTimestampCast::class,
        'expiration_date' => EHealthTimestampCast::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'type_id',
        'report_origin_id',
        'context_id',
        'recorder_id',
        'definition_id',
        'parent_id',
        'created_at',
        'updated_at'
    ];

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'definition_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorder_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'parent_id');
    }

    public function names(): HasMany
    {
        return $this->hasMany(DeviceName::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(DeviceProperty::class);
    }

    public function identifiers(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'device_identifiers');
    }

    /**
     * Scope to eager load all device relationships.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'type.coding',
            'reportOrigin.coding',
            'context.type.coding',
            'recorder.type.coding',
            'definition.type.coding',
            'parent.type.coding',
            'names',
            'properties.code.coding',
            'properties.value.valueCodeableConcept.coding',
            'properties.value.valueQuantity',
            'properties.value.valueRange.low',
            'properties.value.valueRange.high',
            'identifiers.type.coding'
        ]);
    }

    /**
     * Filter devices belonging to the given patient (person or preperson).
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
     * Filter devices recorded within the given encounter, which is stored as the context identifier.
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
