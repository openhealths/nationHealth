<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    public function legalEntityTypes(): BelongsToMany
    {
        return $this->belongsToMany(LegalEntityType::class, 'legal_entity_type_permissions', 'permission_id', 'legal_entity_type_id');
    }
}
