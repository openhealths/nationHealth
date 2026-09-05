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
    public function get_scopes_returns_unique_permission_names(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAllPermissions')->andReturn(collect([
            (object) ['name' => 'party_verification:details'],
            (object) ['name' => 'party_verification:write'],
            (object) ['name' => 'employee:deactivate'],
            (object) ['name' => 'employee:deactivate'],
        ]));

        $scopes = $user->getScopes();

        $this->assertSame(
            'party_verification:details party_verification:write employee:deactivate',
            $scopes
        );
    }
}
