<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthDateCast;
use App\Casts\EHealthTimestampCast;
use App\Enums\DeviceAssociation\Status;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAssociation extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'preperson_id',
        'device_id',
        'status',
        'body_site_id',
        'association_date',
        'recorded',
        'primary_source',
        'report_origin_id',
        'context_id',
        'recorder_id'
    ];

    protected $casts = [
        'status' => Status::class,
        'association_date' => EHealthDateCast::class,
        'recorded' => EHealthTimestampCast::class
    ];

    protected $hidden = [
        'id',
        'person_id',
        'preperson_id',
        'device_id',
        'body_site_id',
        'report_origin_id',
        'context_id',
        'recorder_id',
        'created_at',
        'updated_at'
    ];

    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'device_id');
    }

    public function bodySite(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'body_site_id');
    }

    public function reportOrigin(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'report_origin_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'context_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'recorder_id');
    }

    /**
     * Scope to eager load all device association relationships.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function withAllRelations(Builder $query): Builder
    {
        return $query->with([
            'device.type.coding',
            'bodySite.coding',
            'reportOrigin.coding',
            'context.type.coding',
            'recorder.type.coding'
        ]);
    }

    /**
     * Filter device associations belonging to the given patient (person or preperson).
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
     * Filter device associations recorded within the given encounter, which is stored as the context identifier.
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
