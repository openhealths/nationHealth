<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Employee\RequestStatus;
use App\Enums\Status;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatusColorTest extends TestCase
{
    #[Test]
    public function employee_status_new_uses_yellow_badge(): void
    {
        $this->assertSame('badge-yellow', Status::NEW->color());
        $this->assertSame('badge-yellow', Status::SIGNED->color());
        $this->assertSame('badge-green', Status::APPROVED->color());
        $this->assertSame('badge-red', Status::ENTERED_IN_ERROR->color());
    }

    #[Test]
    public function request_status_pending_uses_yellow_badge(): void
    {
        $this->assertSame('badge-yellow', RequestStatus::NEW->color());
        $this->assertSame('badge-yellow', RequestStatus::SIGNED->color());
        $this->assertSame('badge-green', RequestStatus::APPROVED->color());
        $this->assertSame('badge-red', RequestStatus::REJECTED->color());
    }
}
