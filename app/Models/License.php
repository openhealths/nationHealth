<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\License\Type;
use App\Casts\EHealthDateCast;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'legal_entity_id',
        'type',
        'is_active',
        'issued_by',
        'issued_date',
        'issuer_status',
        'active_from_date',
        'order_no',
        'license_number',
        'expiry_date',
        'what_licensed',
        'is_primary',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'type' => Type::class,
        'issued_date' => EHealthDateCast::class,
        'active_from_date' => EHealthDateCast::class,
        'expiry_date' => EHealthDateCast::class
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}
