<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Enums\Employee\RequestStatus;
use App\Models\Employee\EmployeeRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestPendingStatusTest extends TestCase
{
    #[Test]
    public function local_draft_is_new_without_uuid(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::NEW,
            'uuid' => null,
            'applied_at' => null,
        ]);

        $this->assertTrue($request->isLocalDraft());
        $this->assertFalse($request->isPendingEhealth());
    }

    #[Test]
    public function submitted_new_with_uuid_is_pending_not_draft(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::NEW,
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'applied_at' => null,
        ]);

        $this->assertFalse($request->isLocalDraft());
        $this->assertTrue($request->isPendingEhealth());
        $this->assertSame('Новий', RequestStatus::NEW->label());
    }

    #[Test]
    public function legacy_signed_without_applied_at_is_pending(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::SIGNED,
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'applied_at' => null,
        ]);

        $this->assertFalse($request->isLocalDraft());
        $this->assertTrue($request->isPendingEhealth());
        $this->assertSame('Надіслано', RequestStatus::SIGNED->label());
    }

    #[Test]
    public function submitted_new_with_applied_at_is_still_pending(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::NEW,
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'applied_at' => now(),
        ]);

        $this->assertFalse($request->isLocalDraft());
        $this->assertTrue($request->isPendingEhealth());
    }

    #[Test]
    public function approved_request_is_neither_draft_nor_pending(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::APPROVED,
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'applied_at' => now(),
        ]);

        $this->assertFalse($request->isLocalDraft());
        $this->assertFalse($request->isPendingEhealth());
    }

    #[Test]
    public function filter_choices_exclude_legacy_signed(): void
    {
        $values = array_map(
            static fn (RequestStatus $status): string => $status->value,
            RequestStatus::filterChoices()
        );

        $this->assertContains(RequestStatus::NEW->value, $values);
        $this->assertContains(RequestStatus::APPROVED->value, $values);
        $this->assertNotContains(RequestStatus::SIGNED->value, $values);
    }
}
