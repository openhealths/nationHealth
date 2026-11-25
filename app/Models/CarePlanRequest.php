<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EHealthDateCast;

/**
 * Locally stored application to create a care plan.
 */
class CarePlanRequest extends BaseCarePlan
{
    /**
     * Keep calendar dates in the editor format when read and store clinical selections as JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'period_start' => EHealthDateCast::class,
        'period_end' => EHealthDateCast::class,
        'co_authors' => 'array',
        'episodes' => 'array',
        'medical_records' => 'array',
        'version' => 'integer',
    ];
}
