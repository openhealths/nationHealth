<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

use App\Contracts\ApiErrorTranslator;

/**
 * Raw error contains details and translation context passed to Message mapping.
 * A null entry identifies a top-level error; a null description uses the missing-description fallback.
 */
class ErrorMessageData
{
    public function __construct(
        public readonly ?string $entry,
        public readonly ?string $description,
        public readonly ApiErrorTranslator $translator
    ) {
    }
}
