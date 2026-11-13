<?php

declare(strict_types=1);

namespace Tests\Feature\Equipment;

use App\Enums\User\Role;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipmentSyncPolicyContextTest extends TestCase
{
    #[DataProvider('tokenScopes')]
    public function test_explicit_token_scopes_are_checked_without_web_permissions_or_roles(array $scopes, bool $allowed): void
    {
        $user = $this->userWithScopes(['equipment:read', 'equipment:write']);
        $user->shouldNotReceive('can');
        $user->shouldNotReceive('cannot');
        $user->shouldNotReceive('hasPermissionTo');
        $user->shouldNotReceive('hasAllowedRole');

        $response = Gate::forUser($user)->inspect('sync', [Equipment::class, $scopes]);

        $this->assertSame($allowed, $response->allowed());
        $this->assertSame($allowed ? null : 404, $response->status());
    }

    public static function tokenScopes(): array
    {
        return [
            'no scopes' => [[], false],
            'read' => [['equipment:read'], true],
            'write only' => [['equipment:write'], false],
            'read and write' => [['equipment:read', 'equipment:write'], true],
            'unrelated read' => [['declaration:read'], false],
        ];
    }

    #[DataProvider('pagePermissions')]
    public function test_page_authorization_uses_user_permissions_and_viewing_roles(array $scopes, Role $role, bool $allowed): void
    {
        $user = $this->userWithScopes($scopes);
        $user->shouldReceive('hasAllowedRole')->andReturnUsing(fn (array $roles): bool => in_array($role, $roles, true));

        $response = Gate::forUser($user)->inspect('sync', Equipment::class);

        $this->assertSame($allowed, $response->allowed());
        $this->assertSame($allowed ? null : 404, $response->status());
    }

    public static function pagePermissions(): array
    {
        return [
            'owner without scopes' => [[], Role::OWNER, false],
            'owner with read' => [['equipment:read'], Role::OWNER, true],
            'owner with write' => [['equipment:write'], Role::OWNER, true],
            'doctor with read and write' => [['equipment:read', 'equipment:write'], Role::DOCTOR, true],
            'specialist with read' => [['equipment:read'], Role::SPECIALIST, true],
            'receptionist with read' => [['equipment:read'], Role::RECEPTIONIST, false],
            'receptionist with write' => [['equipment:write'], Role::RECEPTIONIST, false],
        ];
    }

    protected function userWithScopes(array $scopes): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('checkPermissionTo')->andReturnUsing(fn (string $ability): bool => in_array($ability, $scopes, true));

        return $user;
    }
}
