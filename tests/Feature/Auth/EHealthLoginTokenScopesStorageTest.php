<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\EHealth\Services\TokenStorage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Session scopes used for eHealth API gates must come from the OAuth token,
 * not from Spatie-merged role permissions.
 */
class EHealthLoginTokenScopesStorageTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function store_scopes_keeps_oauth_token_scopes_not_spatie_merge(): void
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'scopes_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
        ]);

        $permission = Permission::findOrCreate('party_verification:read', 'web');
        $user->givePermissionTo($permission);

        $oauthScopes = ['party_verification:details', 'employee:read'];

        $storage = app(TokenStorage::class);
        $storage->storeScopes($oauthScopes);

        $this->assertSame(
            $oauthScopes,
            $storage->getTokenScopes(),
            'API gates must see OAuth scopes, not Spatie-only party_verification:read'
        );
        $this->assertNotContains(
            'party_verification:read',
            $storage->getTokenScopes()
        );
    }
}
