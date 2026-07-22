<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthcareService\Status;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class HealthcareService extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'speciality_type',
        'providing_condition',
        'license_id',
        'division_id',
        'category_id',
        'type_id',
        'comment',
        'coverage_area',
        'available_time',
        'not_available',
        'status',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'is_active',
        'legal_entity_id',
        'licensed_healthcare_service',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $casts = [
        'available_time' => 'json',
        'not_available' => 'json',
        'status' => Status::class,
        'ehealth_inserted_at' => 'datetime',
        'created_at' => 'datetime'
    ];

    protected function notAvailable(): Attribute
    {
        return Attribute::make(
            get: static function ($value) {
                if (is_null($value)) {
                    return null;
                }

                $data = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;

                return collect($data)
                    ->map(function ($item) {
                        if (isset($item['during']['start'], $item['during']['end'])) {
                            // parse utc, add timezone
                            $start = Carbon::parse($item['during']['start'])->setTimezone(config('app.timezone'));
                            $end = Carbon::parse($item['during']['end'])->setTimezone(config('app.timezone'));

                            $item['during'] = [
                                'startDate' => $start->format('d.m.Y'),
                                'startTime' => $start->format('H:i'),
                                'endDate' => $end->format('d.m.Y'),
                                'endTime' => $end->format('H:i')
                            ];
                        }

                        return $item;
                    })
                    ->all();
            },
        );
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'type_id');
    }

    /**
     * List of healthcare services for the current legal entity.
     *
     * @param  Builder  $query
     * @param  int  $legalEntityId
     * @return Builder
     */
    #[Scope]
    protected function filterByLegalEntity(Builder $query, int $legalEntityId): Builder
    {
        return $query->where('legal_entity_id', $legalEntityId)
            ->select([
                'id',
                'uuid',
                'legal_entity_id',
                'division_id',
                'speciality_type',
                'providing_condition',
                'ehealth_inserted_at',
                'status',
                'created_at'
            ])
            ->with('division:id,name,status')
            ->orderByDesc('ehealth_inserted_at')
            ->orderByDesc('created_at');
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereLegalEntityId(legalEntity()->id)
            ->whereIsActive(true)
            ->whereStatus(Status::ACTIVE)
            ->with('division:id,name')
            ->select(['division_id', 'uuid', 'speciality_type'])
            ->orderBy('speciality_type');
    }
}
