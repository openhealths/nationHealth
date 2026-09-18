<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Enums\User\Role;
use App\Models\LegalEntity;
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
            self::NEWBORN => __('compositions.type.newborn'),
            self::TEMP_DISABILITY => __('compositions.type.temp_disability'),
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
     * Employee roles allowed to issue this conclusion in the given legal entity type.
     *
     * TV 3.8.1.1 and 3.8.2.1 state the requirement as a pair, not as two independent
     * conditions: an outpatient DOCTOR is not a permitted author of a birth conclusion
     * even though both "outpatient" and "a clinical role" are individually true of them.
     *
     * @return list<Role> Empty when this entity type may not issue the conclusion at all.
     */
    public function allowedAuthorRoles(?string $legalEntityType): array
    {
        return match ($this) {
            // МВН — лікар-спеціаліст у закладі спеціалізованої допомоги.
            self::NEWBORN => $legalEntityType === LegalEntity::TYPE_OUTPATIENT ? [Role::SPECIALIST] : [],
            // МВТН — сімейний лікар на первинці або лікар-спеціаліст на спеціалізованій.
            self::TEMP_DISABILITY => match ($legalEntityType) {
                LegalEntity::TYPE_PRIMARY_CARE => [Role::DOCTOR],
                LegalEntity::TYPE_OUTPATIENT => [Role::SPECIALIST],
                default => [],
            },
        };
    }

    /**
     * Dictionary of print form templates published by eHealth.
     */
    public const string TEMPLATE_DICTIONARY = 'COMPOSITION_TEMPLATE_ID';

    /**
     * Print form template used for this conclusion type (TV 3.8.1.8.2, 3.8.2.8.3).
     *
     * The active COMPOSITION_TEMPLATE_ID dictionary decides which template exists; the
     * hard-coded value is only the fallback for an environment whose dictionaries have
     * not been synced yet, so a renumbered template does not need a code change.
     */
    public function printTemplateId(): string
    {
        $fallback = match ($this) {
            self::NEWBORN => '1000',
            self::TEMP_DISABILITY => '1001',
        };

        $codes = dictionary()->basics()
            ->byName(self::TEMPLATE_DICTIONARY)
            ->asCodeDescription();

        // Some environments publish the template under the conclusion type itself rather
        // than under its numeric id, so both spellings are accepted.
        if ($codes->has($this->value)) {
            return (string) $codes->get($this->value);
        }

        return $fallback;
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
