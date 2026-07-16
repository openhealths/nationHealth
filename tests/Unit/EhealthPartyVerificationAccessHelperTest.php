<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EhealthPartyVerificationAccessHelperTest extends TestCase
{
    #[Test]
    public function admin_with_details_scope_can_access_party_verification(): void
    {
        $this->app['env'] = 'local';

        $token = $this->makeJwt(['scope' => 'employee:read party_verification:details party_verification:write']);
        session()->put(config('ehealth.api.oauth.bearer_token'), Crypt::encryptString($token));

        $this->assertTrue(ehealthCanAccessPartyVerification());
        $this->assertTrue(ehealthHasAnyScope('party_verification:details'));
        $this->assertFalse(ehealthHasScope('party_verification:read'));
    }

    #[Test]
    public function owner_with_read_scope_can_access_party_verification(): void
    {
        $this->app['env'] = 'local';

        $token = $this->makeJwt(['scope' => 'party_verification:read party_verification:write']);
        session()->put(config('ehealth.api.oauth.bearer_token'), Crypt::encryptString($token));

        $this->assertTrue(ehealthCanAccessPartyVerification());
    }

    #[Test]
    public function token_without_verification_scopes_cannot_access(): void
    {
        $this->app['env'] = 'local';

        $token = $this->makeJwt(['scope' => 'employee:read']);
        session()->put(config('ehealth.api.oauth.bearer_token'), Crypt::encryptString($token));

        $this->assertFalse(ehealthCanAccessPartyVerification());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeJwt(array $payload): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $header . '.' . $body . '.signature';
    }
}
