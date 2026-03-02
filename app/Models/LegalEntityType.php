<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany ;

class LegalEntityType extends Model
{
    protected $fillable = [
        'name',
        'localized_name',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'legal_entity_type_roles', 'legal_entity_type_id', 'role_id');
    }

    public function legalEntities(): HasMany
    {
        return $this->hasMany(LegalEntity::class, 'legal_entity_type_id');
    }
}
