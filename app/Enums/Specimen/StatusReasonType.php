<?php

declare(strict_types=1);

namespace App\Enums\Specimen;

/** Rejection and invalidation share a body shape but use different dictionaries. */
enum StatusReasonType: string
{
    case REJECT = 'specimen_reject_reasons';
    case INVALIDATE = 'specimen_invalidate_reasons';
}
