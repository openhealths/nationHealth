<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EHealthDateCast;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status;
use App\Models\Employee\Employee;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    use HasCamelCasing;

    protected $table = 'equipments';

    protected $fillable = [
        'uuid',
        'legal_entity_id',
        'division_id',
        'parent_id',
        'recorder',
        'device_definition_id',
        'type',
        'serial_number',
        'status',
        'availability_status',
        'manufacturer',
        'manufacture_date',
        'model_number',
        'inventory_number',
        'lot_number',
        'expiration_date',
        'note',
        'error_reason',
        'properties',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $hidden = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'status' => Status::class,
        'availability_status' => AvailabilityStatus::class,
        'manufacture_date' => EHealthDateCast::class,
        'expiration_date' => EHealthDateCast::class,
        'ehealth_inserted_at' => 'date:d.m.Y',
        'created_at' => 'date:d.m.Y',
        'properties' => 'array',
    ];

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorder');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }

    public function names(): HasMany
    {
        return $this->hasMany(EquipmentName::class, 'equipment_id');
    }

    #[Scope]
    public function active(Builder $query): Builder
    {
        return $query->whereStatus(Status::ACTIVE);
    }

    /**
     * List of equipments for the current legal entity.
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
                'inventory_number',
                'type',
                'status',
                'availability_status',
                'ehealth_inserted_at',
                'created_at'
            ])
            ->with(['names', 'division:id,name'])
            ->orderByDesc('ehealth_inserted_at')
            ->orderByDesc('created_at');
    }
}
