<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Exceptions\EHealth\EHealthResponseException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EhealthScopeHelpersTest extends TestCase
{
    #[Test]
    public function ehealth_is_missing_scope_error_detects_forbidden_scope_message(): void
    {
        $response = new Response(new \GuzzleHttp\Psr7\Response(
            403,
            [],
            json_encode([
                'error' => [
                    'message' => 'Your scope does not allow to access this resource. Missing allowances: employee:deactivate',
                ],
            ], JSON_THROW_ON_ERROR)
        ));

        $exception = new EHealthResponseException($response);

        $this->assertTrue(ehealthIsMissingScopeError($exception, 'employee:deactivate'));
        $this->assertFalse(ehealthIsMissingScopeError($exception, 'employee:write'));
    }
}
