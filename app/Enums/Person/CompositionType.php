<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * Composition type.
 *
 * The COMPOSITION_TYPES dictionary also publishes ADOPTION and DRIVERS, but the
 * createComposition contract accepts only these two and TV 3.8 covers only these two,
 * so the others are deliberately left out until their own requirements land.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18024857818
 */
enum CompositionType: string
{
    use EnumUtils;

    /** Медичний висновок про народження (МВН). */
    case NEWBORN = 'NEWBORN';

    /** Медичний висновок про тимчасову непрацездатність (МВТН). */
    case TEMP_DISABILITY = 'TEMP_DISABILITY';

    public function label(): string
    {
        return match ($this) {
            self::NEWBORN => __('patients.composition.type.newborn'),
            self::TEMP_DISABILITY => __('patients.composition.type.temp_disability'),
        };
    }

    /**
     * Category pre-selected for the user (TV 3.8.1.5.2).
     */
    public function defaultCategory(): CompositionCategory
    {
        return match ($this) {
            self::NEWBORN => CompositionCategory::LIVE_BIRTH,
            self::TEMP_DISABILITY => CompositionCategory::SICKNESS,
        };
    }

    /**
     * A birth conclusion is always issued for a newborn who has no identity record yet,
     * so its subject is a preperson rather than a person (TV 3.8.1.5.2).
     */
    public function subjectResource(): string
    {
        return match ($this) {
            self::NEWBORN => 'preperson',
            self::TEMP_DISABILITY => 'person',
        };
    }

    /**
     * Dictionary holding the cancellation reasons applicable to this type.
     */
    public function cancellationReasonDictionary(): string
    {
        return match ($this) {
            self::NEWBORN => 'COMPOSITION_CANCELLATION_REASONS_NEWBORN',
            self::TEMP_DISABILITY => 'COMPOSITION_CANCELLATION_REASONS_TEMP_DISABILITY',
        };
    }
}
