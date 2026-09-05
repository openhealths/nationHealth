<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Models\Employee\EmployeeRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestSearchByFullNameTest extends TestCase
{
    #[Test]
    public function search_scope_uses_where_like_abstraction(): void
    {
        $sql = strtolower(
            EmployeeRequest::query()
                ->searchByFullName('Петренко Іван')
                ->toSql()
        );

        $this->assertStringContainsString('last_name', $sql);
        $this->assertStringContainsString('like', $sql);
        // whereLike abstraction (PG still emits ILIKE), not Query Builder where(..., 'ILIKE', ...).
        $this->assertDoesNotMatchRegularExpression("/last_name'\\s*,\\s*'ilike'/", $sql);
        $this->assertStringContainsString("(data->'party'->>'last_name')", $sql);
    }
}
