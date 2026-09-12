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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DeviceDispense extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'based_on_id',
        'status',
        'performer_id',
        'location_id',
        'when_handed_over',
        'note',
        'performer_legal_entity_id',
        'program_id',
        'part_of_id',
        'encounter_id',
        'context_episode_id',
        'origin_episode_id',
        'status_reason_id',
        'explanatory_letter',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $casts = [
        'status' => Status::class,
        'when_handed_over' => EHealthTimestampCast::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class,
        'ehealth_updated_at' => EHealthTimestampCast::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'based_on_id',
        'performer_id',
        'location_id',
        'performer_legal_entity_id',
        'program_id',
        'part_of_id',
        'encounter_id',
        'status_reason_id',
        'created_at',
        'updated_at'
    ];

    protected function ehealthInsertedDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Str::before((string) $this->ehealthInsertedAt, ' ')
        );
    }

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'based_on_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'location_id');
    }

    public function performerLegalEntity(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'performer_legal_entity_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'program_id');
    }

    public function partOf(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'part_of_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'encounter_id');
    }

    public function statusReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(DeviceDispenseDetail::class);
    }

    public function supportingInfo(): BelongsToMany
    {
        return $this->belongsToMany(Identifier::class, 'device_dispense_supporting_info')->withTimestamps();
    }

    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'basedOn.type.coding',
            'performer.type.coding',
            'location.type.coding',
            'performerLegalEntity.type.coding',
            'program.type.coding',
            'partOf.type.coding',
            'encounter.type.coding',
            'statusReason.coding',
            'details.device.type.coding',
            'details.deviceCode.coding',
            'details.programDevice.type.coding',
            'details.quantity',
            'supportingInfo.type.coding'
        ]);
    }

    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $patient instanceof Preperson ? $query->wherePrepersonId($patient->id) : $query->wherePersonId($patient->id);
    }

    #[Scope]
    protected function forEncounter(Builder $query, string $encounterId): Builder
    {
        return $query->whereHas('encounter', static fn (Builder $identifier): Builder => $identifier->whereValue($encounterId));
    }
}