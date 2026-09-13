<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the conclusion enums to the live eHealth dictionaries.
 *
 * The original implementation invented codes (STILL_BIRTH, INDUSTRIAL_ACCIDENT,
 * PROSTHETIC_APPLIANCE and others) and lower-cased the statuses, so these assertions
 * exist to stop values drifting away from COMPOSITION_STATUS and COMPOSITION_CATEGORIES
 * again.
 */
class CompositionEnumsTest extends TestCase
{
    #[Test]
    public function statuses_match_the_dictionary_codes(): void
    {
        $this->assertSame(
            ['PRELIMINARY', 'FINAL', 'AMENDED', 'ENTERED_IN_ERROR'],
            CompositionStatus::values()
        );
    }

    #[Test]
    public function status_resolution_accepts_the_hyphenated_spelling_used_by_the_search_filter(): void
    {
        $this->assertSame(CompositionStatus::ENTERED_IN_ERROR, CompositionStatus::fromEHealth('ENTERED-IN-ERROR'));
        $this->assertSame(CompositionStatus::ENTERED_IN_ERROR, CompositionStatus::fromEHealth('ENTERED_IN_ERROR'));
        $this->assertSame(CompositionStatus::FINAL, CompositionStatus::fromEHealth('final'));
    }

    #[Test]
    public function status_resolution_returns_null_for_unknown_and_empty_input(): void
    {
        $this->assertNull(CompositionStatus::fromEHealth(null));
        $this->assertNull(CompositionStatus::fromEHealth(''));
        $this->assertNull(CompositionStatus::fromEHealth('SOMETHING_ELSE'));
    }

    #[Test]
    public function only_a_draft_can_be_signed_and_only_a_signed_one_can_be_cancelled(): void
    {
        $this->assertTrue(CompositionStatus::PRELIMINARY->isSignable());
        $this->assertFalse(CompositionStatus::FINAL->isSignable());

        $this->assertTrue(CompositionStatus::FINAL->isCancellable());
        $this->assertFalse(CompositionStatus::PRELIMINARY->isCancellable());
        $this->assertFalse(CompositionStatus::ENTERED_IN_ERROR->isCancellable());
        $this->assertFalse(CompositionStatus::AMENDED->isCancellable());
    }

    #[Test]
    public function a_birth_conclusion_has_exactly_one_category(): void
    {
        $this->assertSame(
            [CompositionCategory::LIVE_BIRTH],
            CompositionCategory::forType(CompositionType::NEWBORN)
        );
    }

    #[Test]
    public function disability_categories_match_the_dictionary_codes(): void
    {
        $codes = array_map(
            static fn (CompositionCategory $category) => $category->value,
            CompositionCategory::forType(CompositionType::TEMP_DISABILITY)
        );

        $this->assertSame(
            [
                'SICKNESS',
                'CHILD_CARE',
                'FAMILY_CARE',
                'PARENTAL_CARE',
                'PREGNANCY',
                'QUARANTINE',
                'COVID19',
                'TEMP_TRANSFER',
                'PROSTHETIC',
                'RESTORATION',
            ],
            $codes
        );
    }

    #[Test]
    public function categories_invented_by_the_earlier_implementation_no_longer_exist(): void
    {
        foreach (['STILL_BIRTH', 'INDUSTRIAL_ACCIDENT', 'PROSTHETIC_APPLIANCE', 'CARE_OF_SICK_FAMILY_MEMBER'] as $code) {
            $this->assertNull(
                CompositionCategory::tryFrom($code),
                "$code is not a COMPOSITION_CATEGORIES value and must not be reintroduced."
            );
        }
    }

    #[Test]
    public function every_category_reports_the_type_it_belongs_to(): void
    {
        $this->assertSame(CompositionType::NEWBORN, CompositionCategory::LIVE_BIRTH->type());
        $this->assertSame(CompositionType::TEMP_DISABILITY, CompositionCategory::PREGNANCY->type());
    }

    #[Test]
    public function only_pregnancy_restricts_the_selectable_validity_periods(): void
    {
        $this->assertTrue(CompositionCategory::PREGNANCY->hasRestrictedValidityPeriods());
        $this->assertFalse(CompositionCategory::SICKNESS->hasRestrictedValidityPeriods());
    }

    #[Test]
    public function an_unidentified_patient_is_limited_to_sickness_and_child_care(): void
    {
        $this->assertTrue(CompositionCategory::SICKNESS->isAllowedForPreperson());
        $this->assertTrue(CompositionCategory::CHILD_CARE->isAllowedForPreperson());
        $this->assertFalse(CompositionCategory::PREGNANCY->isAllowedForPreperson());
        $this->assertFalse(CompositionCategory::COVID19->isAllowedForPreperson());
    }

    #[Test]
    public function a_birth_conclusion_is_filed_against_a_preperson(): void
    {
        $this->assertSame('preperson', CompositionType::NEWBORN->subjectResource());
        $this->assertSame('person', CompositionType::TEMP_DISABILITY->subjectResource());
    }

    #[Test]
    public function each_type_defaults_to_its_own_category_and_reason_dictionary(): void
    {
        $this->assertSame(CompositionCategory::LIVE_BIRTH, CompositionType::NEWBORN->defaultCategory());
        $this->assertSame(CompositionCategory::SICKNESS, CompositionType::TEMP_DISABILITY->defaultCategory());

        $this->assertSame(
            'COMPOSITION_CANCELLATION_REASONS_NEWBORN',
            CompositionType::NEWBORN->cancellationReasonDictionary()
        );
        $this->assertSame(
            'COMPOSITION_CANCELLATION_REASONS_TEMP_DISABILITY',
            CompositionType::TEMP_DISABILITY->cancellationReasonDictionary()
        );
    }
}
