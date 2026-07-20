<?php

declare(strict_types=1);

namespace Tests\Unit\User;

use App\Models\User;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserScopesFilterTest extends TestCase
{
    #[Test]
    public function get_scopes_keeps_only_known_ar_scopes(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAllPermissions')->andReturn(collect([
            (object) ['name' => 'legacy:obsolete:scope'],
            (object) ['name' => 'party_verification:details'],
            (object) ['name' => 'party_verification:write'],
            (object) ['name' => 'employee:deactivate'],
        ]));

        $scopes = $user->getScopes();

        $this->assertStringNotContainsString('legacy:obsolete:scope', $scopes);
        $this->assertStringContainsString('party_verification:details', $scopes);
        $this->assertStringContainsString('party_verification:write', $scopes);
        $this->assertStringContainsString('employee:deactivate', $scopes);
    }
}
