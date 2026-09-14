<?php

declare(strict_types=1);

namespace App\Enums\Person;

/**
 * The request an outstanding composition async job was scheduled for.
 *
 * eHealth answers create, sign, cancel and the ERLN retry with the same job shape, so
 * the operation has to be remembered alongside the job id for the poller to know what a
 * DONE actually means locally.
 */
enum CompositionAsyncOperation: string
{
    case CREATE = 'CREATE';

    case SIGN = 'SIGN';

    case CANCEL = 'CANCEL';

    case ERLN_RETRY = 'ERLN_RETRY';
}
