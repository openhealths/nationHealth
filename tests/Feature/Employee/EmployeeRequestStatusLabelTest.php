<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Employee\RequestStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestStatusLabelTest extends TestCase
{
    #[Test]
    public function pending_request_statuses_display_as_new(): void
    {
        $this->assertSame('Новий', RequestStatus::NEW->label());
        $this->assertSame('Новий', RequestStatus::SIGNED->label());
    }
}
