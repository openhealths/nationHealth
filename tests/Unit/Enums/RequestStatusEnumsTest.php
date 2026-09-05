<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Person\ApprovalStatus;
use App\Enums\Person\DeviceRequestStatus;
use App\Enums\Person\MedicationRequestStatus;
use App\Enums\Person\ServiceRequestStatus;
use Tests\TestCase;

class RequestStatusEnumsTest extends TestCase
{
    /**
     * eHealth answers in upper case while our tables store lower case, and the two disagree
     * about `-` versus `_`, so every spelling has to land on the same case.
     */
    public function test_statuses_resolve_regardless_of_case_and_separator(): void
    {
        $this->assertSame(
            MedicationRequestStatus::ENTERED_IN_ERROR,
            MedicationRequestStatus::resolve('ENTERED_IN_ERROR')
        );
        $this->assertSame(
            MedicationRequestStatus::ENTERED_IN_ERROR,
            MedicationRequestStatus::resolve('entered-in-error')
        );
        $this->assertSame(MedicationRequestStatus::NEW, MedicationRequestStatus::resolve('NEW'));
        $this->assertSame(
            ServiceRequestStatus::IN_PROGRESS,
            ServiceRequestStatus::resolve('in-progress')
        );
        $this->assertSame(ApprovalStatus::NEW, ApprovalStatus::resolve('new'));
        $this->assertSame(ApprovalStatus::APPROVED, ApprovalStatus::resolve('approved'));
    }

    public function test_unknown_and_empty_statuses_resolve_to_nothing(): void
    {
        $this->assertNull(MedicationRequestStatus::resolve('teleported'));
        $this->assertNull(MedicationRequestStatus::resolve(''));
        $this->assertNull(MedicationRequestStatus::resolve(null));
    }

    public function test_unknown_statuses_are_still_shown_rather_than_blanked(): void
    {
        // A status eHealth adds later should appear in the UI instead of vanishing.
        $this->assertSame('teleported', MedicationRequestStatus::labelFor('teleported'));
        $this->assertSame('badge-dark', MedicationRequestStatus::colorFor('teleported'));
        $this->assertSame('—', MedicationRequestStatus::labelFor(null));
    }

    public function test_prescription_labels_keep_the_masculine_wording(): void
    {
        // "рецепт" is masculine, so prescriptions cannot reuse the impersonal care plan wording.
        $this->assertSame('Активний', MedicationRequestStatus::ACTIVE->label());
        $this->assertSame('Виконаний', MedicationRequestStatus::COMPLETED->label());
        $this->assertSame('Відхилений', MedicationRequestStatus::REJECTED->label());
    }

    public function test_referral_labels_keep_the_neuter_wording(): void
    {
        $this->assertSame('Активне', ServiceRequestStatus::ACTIVE->label());
        $this->assertSame('Виконане', ServiceRequestStatus::COMPLETED->label());
        $this->assertSame('Активне', DeviceRequestStatus::ACTIVE->label());
    }

    public function test_every_status_has_a_translated_label_and_a_badge_colour(): void
    {
        $enums = [
            MedicationRequestStatus::class,
            ServiceRequestStatus::class,
            DeviceRequestStatus::class,
            ApprovalStatus::class,
        ];

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $label = $case->label();

                $this->assertStringNotContainsString(
                    'care-plan.',
                    $label,
                    $enum.'::'.$case->name.' has no translation'
                );
                $this->assertNotSame('', $label);
                $this->assertStringStartsWith('badge-', $case->color());
            }
        }
    }

    public function test_unsigned_prescriptions_are_the_ones_that_can_be_withdrawn_without_a_signature(): void
    {
        $this->assertTrue(MedicationRequestStatus::DRAFT->isUnsigned());
        $this->assertTrue(MedicationRequestStatus::NEW->isUnsigned());
        $this->assertFalse(MedicationRequestStatus::ACTIVE->isUnsigned());
        $this->assertFalse(MedicationRequestStatus::SIGNED->isUnsigned());
    }

    public function test_approval_states_distinguish_granted_from_awaiting_the_patient(): void
    {
        // The column holds both spellings, so both have to answer the same question.
        $this->assertTrue(ApprovalStatus::ACTIVE->isGranted());
        $this->assertTrue(ApprovalStatus::APPROVED->isGranted());
        $this->assertFalse(ApprovalStatus::NEW->isGranted());

        $this->assertTrue(ApprovalStatus::NEW->isAwaitingPatient());
        $this->assertTrue(ApprovalStatus::PENDING->isAwaitingPatient());
        $this->assertFalse(ApprovalStatus::INACTIVE->isAwaitingPatient());
    }

    public function test_approval_storage_canonicalises_the_old_uppercase_spellings(): void
    {
        $this->assertSame('pending', ApprovalStatus::forStorage('NEW'));
        $this->assertSame('pending', ApprovalStatus::forStorage('new'));
        $this->assertSame('active', ApprovalStatus::forStorage('APPROVED'));
        $this->assertSame('active', ApprovalStatus::forStorage('active'));
        $this->assertSame('inactive', ApprovalStatus::forStorage('inactive'));
        $this->assertSame('pending', ApprovalStatus::forStorage(null));
    }

    public function test_device_prescriptions_are_never_taken_into_work_or_queued(): void
    {
        // Those are referral executor states; a device prescription is dispensed against.
        $this->assertNull(DeviceRequestStatus::resolve('in_progress'));
        $this->assertNull(DeviceRequestStatus::resolve('in_queue'));
        $this->assertNotNull(ServiceRequestStatus::resolve('in_progress'));
    }
}
