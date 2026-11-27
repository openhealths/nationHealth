<?php

declare(strict_types=1);

namespace App\Dto\Ehealth\Error;

use App\Contracts\ApiErrorTranslator;

/**
 * One eHealth error with its translation context. A field error may omit its entry.
 */
class ErrorMessageData
{
    public function __construct(
        public readonly ?string $entry,
        public readonly ?string $description,
        public readonly ApiErrorTranslator $translator,
        public readonly ?string $rule = null,
        public readonly array $params = [],
        public readonly bool $isField = true
    ) {
    }
}
