<?php

declare(strict_types=1);

namespace Tests\Unit\Enums\Party;

use App\Enums\Party\VerificationStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerificationStatusColorTest extends TestCase
{
    #[Test]
    public function color_matches_party_verify_badge_palette(): void
    {
        $this->assertSame('badge-green', VerificationStatus::VERIFIED->color());
        $this->assertSame('badge-yellow', VerificationStatus::VERIFICATION_NEEDED->color());
        $this->assertSame('badge-red', VerificationStatus::NOT_VERIFIED->color());
        $this->assertSame('badge-gray', VerificationStatus::VERIFICATION_NOT_NEEDED->color());
    }

    #[Test]
    public function employee_index_shows_stream_statuses_and_uses_color(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/employee-index.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('$partyVerification?->color()', $blade);
        $this->assertStringContainsString("partyVerificationDetails(\$party)", $blade);
        $this->assertStringContainsString("'drfo', 'dracs_death', 'dms_passport'", $blade);
    }
}
