<?php

declare(strict_types=1);

namespace App\Dto\Transform;

use App\Models\MedicalEvents\Sql\CodeableConcept as CodeableConceptModel;

/**
 * Codeable concept transforms for #[Map], referenced as [CodeableConcept::class, 'method'].
 */
class CodeableConcept
{
    /**
     * Loaded codeable concept with its coding as an array, null when the concept is absent.
     * The coding relation has to be loaded beforehand.
     *
     * @param  CodeableConceptModel|null  $codeableConcept
     * @return array|null
     */
    public static function toArray(?CodeableConceptModel $codeableConcept): ?array
    {
        return $codeableConcept?->toArray();
    }
}
