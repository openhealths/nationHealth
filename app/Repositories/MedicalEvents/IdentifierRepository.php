<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Identifier as SqlIdentifier;

class IdentifierRepository extends BaseRepository
{
    /**
     * Create identifier in DB.
     *
     * @param  string  $value
     * @param  string|null  $displayValue
     * @return SqlIdentifier
     */
    public function store(string $value, ?string $displayValue = null): SqlIdentifier
    {
        return $this->model::create([
            'value' => $value,
            'display_value' => $displayValue
        ]);
    }
}
