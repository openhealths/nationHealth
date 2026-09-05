<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Livewire\Employee\Concerns\DeletesEmployeeRequestDraft;
use App\Livewire\Employee\EmployeeIndex;
use App\Livewire\EmployeeRequest\EmployeeRequestIndex;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class EmployeeRequestDraftDeleteActionsTest extends TestCase
{
    #[Test]
    public function employee_request_index_exposes_draft_delete_actions(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(EmployeeRequestIndex::class))->getMethods()
        );

        $this->assertContains('confirmRequestDeletion', $methods);
        $this->assertContains('deleteRequest', $methods);
        $this->assertContains('closeDeleteModal', $methods);
        $this->assertContains(
            DeletesEmployeeRequestDraft::class,
            class_uses_recursive(EmployeeRequestIndex::class)
        );
    }

    #[Test]
    public function employee_index_reuses_the_same_draft_delete_trait(): void
    {
        $this->assertContains(
            DeletesEmployeeRequestDraft::class,
            class_uses_recursive(EmployeeIndex::class)
        );
    }
}
