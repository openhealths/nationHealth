<?php

namespace App\Models;

use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Connection extends Model
{
    use HasCamelCasing;

    protected $table = 'client_connections';

    protected $fillable = [
        'uuid',
        'legal_entity_id',
        'consumer_uuid',
        'redirect_uri',
        'ehealth_inserted_at',
        'ehealth_updated_at',
    ];

    /**
     * Relation to the reference LegalEntities entry.
     *
     * @return BelongsTo
     */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'legal_entity_id', 'legal_entity_id');
    }
}
