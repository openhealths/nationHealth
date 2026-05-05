<?php

declare(strict_types=1);

namespace App\Enums\LegalEntity;

use App\Traits\EnumUtils;

enum ReorganizationTypes: string
{
    use EnumUtils;

    case ACCESSION = 'ACCESSION';
    case MERGING = 'MERGING';
    case DIVIDING = 'DIVIDING';
    case SEPARATING = 'SEPARATING';
    case TRANSFORMATION = 'TRANSFORMATION';

    public function label(): string
    {
        return match ($this) {
            self::ACCESSION => __('forms.reorganization.accession'),
            self::MERGING => __('forms.reorganization.merging'),
            self::DIVIDING => __('forms.reorganization.dividing'),
            self::SEPARATING => __('forms.reorganization.separating'),
            self::TRANSFORMATION => __('forms.reorganization.transformation')
        };
    }

    public static function only(array $names): array
    {
        return collect(self::cases())
            ->filter(fn ($case) => in_array($case->name, $names, true))
            ->map(fn ($case) => $case->value)
            ->values()
            ->all();
    }
}
