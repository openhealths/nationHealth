<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Controllers\HomeController;
use App\Models\LegalEntity;
use App\Models\User;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardRedirectTest extends TestCase
{
    #[DataProvider('loginScopes')]
    public function test_dashboard_opens_an_accessible_registry(
        array $roles,
        array $missingScopes,
        string $expectedRoute
    ): void {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 1;
        $this->instance('legalEntity', $legalEntity);

        $scopes = collect($roles)
            ->flatMap(fn (string $role): array => config('ehealth.roles.' . $role))
            ->diff($missingScopes);
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->shouldReceive('hasPermissionTo')->andReturnUsing(
            fn (string $permission): bool => $scopes->contains($permission)
        );
        $user->shouldReceive('getAllowedRolesAttribute')->andReturn(collect($roles));
        $this->actingAs($user, 'ehealth');

        $response = new HomeController()->dashboard();

        $this->assertSame(
            route($expectedRoute, ['legalEntity' => $legalEntity->id]),
            $response->getTargetUrl()
        );
    }

    public static function loginScopes(): array
    {
        return [
            'owner without patient access' => [['OWNER'], [], 'employee.index'],
            'doctor with patient access' => [['DOCTOR'], [], 'persons.index'],
            'owner and doctor with patient access' => [['OWNER', 'DOCTOR'], [], 'persons.index'],
            'doctor without patient read scope' => [['DOCTOR'], ['person:read'], 'employee.index'],
        ];
    }
}
