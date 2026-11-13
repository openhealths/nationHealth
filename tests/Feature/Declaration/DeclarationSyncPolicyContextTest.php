<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeclarationSyncPolicyContextTest extends TestCase
{
    #[DataProvider('scopeCombinations')]
    public function test_explicit_token_scopes_are_checked_without_web_context_or_user_permissions(array $scopes, bool $allowed): void
    {
        $this->assertNull(legalEntity());
        $user = $this->userWithScopes(['declaration:read', 'declaration_request:read', 'person:read']);
        $user->shouldNotReceive('can');
        $user->shouldNotReceive('cannot');
        $user->shouldNotReceive('hasPermissionTo');

        $response = Gate::forUser($user)->inspect('sync', [Declaration::class, $this->legalEntity(), $scopes]);

        $this->assertSame($allowed, $response->allowed());
        $this->assertSame($allowed ? null : 404, $response->status());
    }

    #[DataProvider('scopeCombinations')]
    public function test_page_authorization_uses_current_legal_entity_and_user_permissions(array $scopes, bool $allowed): void
    {
        $this->instance('legalEntity', $this->legalEntity());
        $user = $this->userWithScopes($scopes);

        $this->assertSame($allowed, $user->can('sync', Declaration::class));
    }

    public static function scopeCombinations(): array
    {
        $requiredScopes = ['declaration:read', 'declaration_request:read', 'person:read'];
        $cases = [];

        for ($mask = 0; $mask < 8; $mask++) {
            $scopes = array_values(array_filter($requiredScopes, fn (string $scope, int $index): bool => ($mask & (1 << $index)) !== 0, ARRAY_FILTER_USE_BOTH));
            $cases[$scopes === [] ? 'no scopes' : implode(' ', $scopes)] = [$scopes, $mask === 7];
        }

        return $cases;
    }

    #[DataProvider('legalEntityContexts')]
    public function test_current_legal_entity_controls_status_and_type_checks(string $status, string $type, bool $allowed): void
    {
        $this->instance('legalEntity', $this->legalEntity($status, $type));
        $scopes = ['declaration:read', 'declaration_request:read', 'person:read'];
        $user = $this->userWithScopes($scopes);

        $response = Gate::forUser($user)->inspect('sync', Declaration::class);

        $this->assertSame($allowed, $response->allowed());
        $this->assertSame($allowed ? null : 404, $response->status());
    }

    #[DataProvider('legalEntityContexts')]
    public function test_explicit_legal_entity_takes_precedence_over_web_context(string $status, string $type, bool $allowed): void
    {
        $this->instance('legalEntity', $this->legalEntity($allowed ? 'CLOSED' : 'ACTIVE'));
        $scopes = ['declaration:read', 'declaration_request:read', 'person:read'];
        $user = $this->userWithScopes([]);

        $response = Gate::forUser($user)->inspect('sync', [Declaration::class, $this->legalEntity($status, $type), $scopes]);

        $this->assertSame($allowed, $response->allowed());
        $this->assertSame($allowed ? null : 404, $response->status());
    }

    public static function legalEntityContexts(): array
    {
        return [
            'active primary care' => ['ACTIVE', 'PRIMARY_CARE', true],
            'reorganized primary care' => ['REORGANIZED', 'PRIMARY_CARE', true],
            'active MSP' => ['ACTIVE', 'MSP', true],
            'active MSP pharmacy' => ['ACTIVE', 'MSP_PHARMACY', true],
            'active limited MSP' => ['ACTIVE', 'MSP_LIMITED', true],
            'new' => ['NEW', 'PRIMARY_CARE', false],
            'suspended' => ['SUSPENDED', 'PRIMARY_CARE', false],
            'closed' => ['CLOSED', 'PRIMARY_CARE', false],
            'outpatient' => ['ACTIVE', 'OUTPATIENT', false],
            'pharmacy' => ['ACTIVE', 'PHARMACY', false],
            'emergency' => ['ACTIVE', 'EMERGENCY', false],
        ];
    }

    public function test_owner_can_view_registry_without_permission_to_synchronize(): void
    {
        $this->instance('legalEntity', $this->legalEntity());
        $user = $this->userWithScopes(config('ehealth.roles.OWNER'));

        $this->assertTrue($user->can('viewAny', Declaration::class));
        $this->assertFalse($user->can('sync', Declaration::class));
    }

    public function test_missing_legal_entity_context_is_denied(): void
    {
        $this->assertNull(legalEntity());
        $user = $this->userWithScopes(['declaration:read', 'declaration_request:read', 'person:read']);

        $response = Gate::forUser($user)->inspect('sync', Declaration::class);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    protected function legalEntity(string $status = 'ACTIVE', string $type = 'PRIMARY_CARE'): LegalEntity
    {
        $legalEntity = new LegalEntity();
        $legalEntity->setRawAttributes(['id' => 1, 'status' => $status]);
        $legalEntity->setRelation('type', new LegalEntityType(['name' => $type]));

        return $legalEntity;
    }

    protected function userWithScopes(array $scopes): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('checkPermissionTo')->andReturnUsing(fn (string $ability): bool => in_array($ability, $scopes, true));

        return $user;
    }
}
