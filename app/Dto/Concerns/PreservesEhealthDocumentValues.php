<?php

declare(strict_types=1);

namespace App\Dto\Concerns;

use App\Dto\EhealthMapping;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** Clinical contracts preserve zero, false, explicit lists and literal keys inside document arrays. */
trait PreservesEhealthDocumentValues
{
    use EhealthMapping;

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        return $data;
    }
}
