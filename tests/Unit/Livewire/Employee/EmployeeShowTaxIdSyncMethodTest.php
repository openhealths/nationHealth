<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\EmployeeRequestShow;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class EmployeeShowTaxIdSyncMethodTest extends TestCase
{
    #[Test]
    public function employee_request_show_exposes_sync_tax_id_from_document(): void
    {
        $this->assertTrue(
            (new ReflectionClass(EmployeeRequestShow::class))->hasMethod('syncTaxIdFromDocument')
        );
        $this->assertTrue(
            (new ReflectionClass(EmployeeRequestShow::class))->hasMethod('toggleNoTaxId')
        );
    }
}
