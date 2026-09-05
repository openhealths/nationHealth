<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'legal_entity_id',
        'user_uuid',
        'legal_entity_type_id',
        'name',
        'settings',
        'is_blocked',
        'block_reason',
        'ehealth_inserted_at',
        'ehealth_updated_at',
    ];

    /**
     * Relation to the reference LegalEntityTypes entry.
     *
     * @return BelongsTo
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class, 'legal_entity_id', 'legal_entity_id');
    }
}
