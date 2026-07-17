<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\EHealthLoginController;
use App\Models\LegalEntity;
use App\Support\EHealthKnownScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EHealthLoginScopeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    #[Test]
    public function token_scope_validation_ignores_unknown_scopes_when_known_remain(): void
    {
        $legalEntity = $this->createLegalEntity();

        $controller = new EHealthLoginController();
        $method = new ReflectionMethod($controller, 'validateEHealthTokenResponse');

        $payload = [
            'details' => [
                'client_id' => $legalEntity->uuid,
                'scope' => 'employee:read legacy:obsolete:scope party_verification:details',
                'refresh_token' => 'refresh-token',
            ],
            'user_id' => '11111111-1111-1111-1111-111111111111',
            'value' => 'access-token',
            'expires_at' => now()->addHour()->timestamp,
        ];

        $validator = $method->invoke($controller, $payload);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    #[Test]
    public function known_scopes_filter_keeps_only_ar_scopes(): void
    {
        $filtered = EHealthKnownScopes::filter([
            'employee:read',
            'legacy:obsolete:scope',
            'party_verification:details',
            '',
        ]);

        $this->assertSame(
            ['employee:read', 'party_verification:details'],
            $filtered
        );
    }

    #[Test]
    public function token_scope_validation_rejects_when_no_known_scopes_remain(): void
    {
        $legalEntity = $this->createLegalEntity();

        $controller = new EHealthLoginController();
        $method = new ReflectionMethod($controller, 'validateEHealthTokenResponse');

        $payload = [
            'details' => [
                'client_id' => $legalEntity->uuid,
                'scope' => 'totally:fake:scope',
                'refresh_token' => 'refresh-token',
            ],
            'user_id' => '11111111-1111-1111-1111-111111111111',
            'value' => 'access-token',
            'expires_at' => now()->addHour()->timestamp,
        ];

        $validator = $method->invoke($controller, $payload);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('totally:fake:scope', $validator->errors()->first('details.scope'));
    }

    private function createLegalEntity(): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }
}
