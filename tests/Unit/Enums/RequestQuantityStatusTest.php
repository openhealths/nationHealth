<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\MedicalEvents\RequestQuantityStatus;
use PHPUnit\Framework\TestCase;

class RequestQuantityStatusTest extends TestCase
{
    public function test_issued_quantity_keeps_the_legacy_excluded_status_spellings_and_order(): void
    {
        $this->assertSame([
            'draft', 'new', 'cancelled', 'rejected', 'declined', 'entered-in-error', 'entered_in_error', 'expired',
            'DRAFT', 'NEW', 'CANCELLED', 'REJECTED', 'DECLINED', 'ENTERED-IN-ERROR', 'ENTERED_IN_ERROR', 'EXPIRED',
        ], RequestQuantityStatus::excluded());
    }

    public function test_reservation_includes_drafts_while_released_requests_do_not_occupy_quantity(): void
    {
        $this->assertSame([
            'cancelled', 'rejected', 'declined', 'entered-in-error', 'entered_in_error', 'expired',
            'CANCELLED', 'REJECTED', 'DECLINED', 'ENTERED-IN-ERROR', 'ENTERED_IN_ERROR', 'EXPIRED',
        ], RequestQuantityStatus::excluded(reserveDrafts: true));
    }
}
