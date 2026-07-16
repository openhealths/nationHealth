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
    public function get_scopes_strips_unsupported_party_verification_read(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAllPermissions')->andReturn(collect([
            (object) ['name' => 'party_verification:read'],
            (object) ['name' => 'party_verification:details'],
            (object) ['name' => 'party_verification:write'],
            (object) ['name' => 'employee:deactivate'],
        ]));

        $scopes = $user->getScopes();

        $this->assertStringNotContainsString('party_verification:read', $scopes);
        $this->assertStringContainsString('party_verification:details', $scopes);
        $this->assertStringContainsString('party_verification:write', $scopes);
        $this->assertStringContainsString('employee:deactivate', $scopes);
    }
}
