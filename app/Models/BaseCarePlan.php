<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Person\Person;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Share the patient, facility and diagnosis attributes of a care plan and its local request.
 */
abstract class BaseCarePlan extends Model
{
    use HasCamelCasing;

    /**
     * Keep the diagnosis concepts as an array for either model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['addresses' => 'array'];
    }

    /** Patient for whom the plan or request was created. */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Facility responsible for the plan or request. */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}
