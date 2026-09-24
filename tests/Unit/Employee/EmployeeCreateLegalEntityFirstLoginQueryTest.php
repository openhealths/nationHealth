<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeCreateLegalEntityFirstLoginQueryTest extends TestCase
{
    #[Test]
    public function employee_create_must_not_exclude_pending_requests_with_applied_at(): void
    {
        $source = file_get_contents(app_path('Listeners/eHealth/EmployeeCreate.php'));

        $this->assertNotFalse($source);
        // LE create stamps applied_at while status stays NEW (LegalEntity::createEmployeeRequest).
        // Filtering whereNull(applied_at) skips that OWNER request and breaks first login.
        $this->assertStringNotContainsString(
            "pendingEhealth()\n                            ->whereNull('applied_at')",
            $source
        );
        $this->assertStringContainsString(
            'Do not gate on applied_at: LE create stamps applied_at',
            $source
        );
    }

    #[Test]
    public function owner_new_replace_must_nullsafe_compare_when_no_local_owner(): void
    {
        $source = file_get_contents(app_path('Listeners/OwnerNewReplace.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'if ($oldOwner && $oldOwner->uuid === ($newOwner[\'uuid\'] ?? null))',
            $source
        );
        $this->assertStringNotContainsString(
            'if ($oldOwner->uuid === $newOwner[\'uuid\'])',
            $source
        );
    }
}
