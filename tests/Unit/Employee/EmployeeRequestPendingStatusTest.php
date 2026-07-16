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
    public function new_without_uuid_is_local_draft(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::NEW,
            'uuid' => null,
        ]);

        $this->assertTrue($request->isLocalDraft());
        $this->assertFalse($request->isPendingEhealth());
    }

    #[Test]
    public function new_with_uuid_is_pending_ehealth_not_draft(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::NEW,
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);

        $this->assertFalse($request->isLocalDraft());
        $this->assertTrue($request->isPendingEhealth());
    }

    #[Test]
    public function signed_legacy_is_pending_and_labelled_sent(): void
    {
        $request = new EmployeeRequest([
            'status' => RequestStatus::SIGNED,
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);

        $this->assertFalse($request->isLocalDraft());
        $this->assertTrue($request->isPendingEhealth());
        $this->assertSame('Надіслано', RequestStatus::SIGNED->label());
        $this->assertSame('Новий', RequestStatus::NEW->label());
    }
}
