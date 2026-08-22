<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * Composition category, limited to the codes reachable from the two types in TV 3.8.
 *
 * Descriptions are not duplicated here: the user-facing text comes from the
 * COMPOSITION_CATEGORIES dictionary so it stays in step with eHealth. This enum exists
 * only so that the categories we branch behaviour on are type-checked.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18352930878
 */
enum CompositionCategory: string
{
    use EnumUtils;

    public const string DICTIONARY = 'COMPOSITION_CATEGORIES';

    // МВН — birth conclusion.

    /** За результатом огляду пологів. */
    case LIVE_BIRTH = 'LIVE_BIRTH';

    // МВТН — temporary disability conclusion.

    /** Захворювання або травма загального характеру. */
    case SICKNESS = 'SICKNESS';

    /** Догляд за хворою дитиною. */
    case CHILD_CARE = 'CHILD_CARE';

    /** Догляд за хворим членом сім'ї. */
    case FAMILY_CARE = 'FAMILY_CARE';

    /** Догляд за дитиною у разі хвороби особи, яка доглядає за дитиною. */
    case PARENTAL_CARE = 'PARENTAL_CARE';

    /** Вагітність та пологи. */
    case PREGNANCY = 'PREGNANCY';

    /** Карантин. */
    case QUARANTINE = 'QUARANTINE';

    /** Обсервація та/або самоізоляція SARS-CoV2. */
    case COVID19 = 'COVID19';

    /** Переведення особи на легшу роботу. */
    case TEMP_TRANSFER = 'TEMP_TRANSFER';

    /** Ортопедичне протезування. */
    case PROSTHETIC = 'PROSTHETIC';

    /** Лікування в санаторно-курортному закладі. */
    case RESTORATION = 'RESTORATION';

    /**
     * Categories selectable for a given conclusion type.
     *
     * @return array<self>
     */
    public static function forType(CompositionType $type): array
    {
        return match ($type) {
            CompositionType::NEWBORN => [self::LIVE_BIRTH],
            CompositionType::TEMP_DISABILITY => [
                self::SICKNESS,
                self::CHILD_CARE,
                self::FAMILY_CARE,
                self::PARENTAL_CARE,
                self::PREGNANCY,
                self::QUARANTINE,
                self::COVID19,
                self::TEMP_TRANSFER,
                self::PROSTHETIC,
                self::RESTORATION,
            ],
        };
    }

    public function type(): CompositionType
    {
        return $this === self::LIVE_BIRTH
            ? CompositionType::NEWBORN
            : CompositionType::TEMP_DISABILITY;
    }

    /**
     * Pregnancy conclusions may only use the validity periods allowed by the
     * EMAL_VALIDATION_PREGNANCY_* configuration, and the end date is picked from that
     * list instead of being entered freely (TV 3.8.2.5.4).
     */
    public function hasRestrictedValidityPeriods(): bool
    {
        return $this === self::PREGNANCY;
    }

    /**
     * Categories a SPECIALIST may issue for an unidentified patient (TV 3.8.2.6).
     */
    public function isAllowedForPreperson(): bool
    {
        return in_array($this, [self::SICKNESS, self::CHILD_CARE], true);
    }

    /**
     * Label from the eHealth dictionary, falling back to the raw code.
     */
    public function label(): string
    {
        return dictionary()->basics()
            ->byName(self::DICTIONARY)
            ->asCodeDescription()
            ->get($this->value, $this->value);
    }
}
