<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Exceptions\EHealth\EHealthResponseException;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke-tests confirming that the Employee module correctly delegates
 * the 403 "Party is not verified" case to EHealthResponseException::handle().
 *
 * Full coverage of the exception behaviour is in:
 *
 * @see \Tests\Feature\EHealth\EHealthResponseExceptionPartyNotVerifiedTest
 */
class EmployeePartyNotVerifiedTest extends TestCase
{
    #[Test]
    public function exception_is_detected_as_party_not_verified_on_403_with_matching_body(): void
    {
        $exception = $this->makeException(403, 'Access denied. Party is not verified');

        $this->assertTrue($exception->isPartyNotVerified());
    }

    #[Test]
    public function exception_is_not_detected_as_party_not_verified_for_other_403_errors(): void
    {
        $exception = $this->makeException(403, 'Access denied. Insufficient permissions.');

        $this->assertFalse($exception->isPartyNotVerified());
    }

    #[Test]
    public function party_not_verified_translation_key_contains_required_text(): void
    {
        $message = __('errors.ehealth.messages.party_not_verified');

        $this->assertStringContainsString('Увага!', $message);
        $this->assertStringContainsString('ДПС або ДРАЦСГ', $message);
        $this->assertStringContainsString('відділу кадрів', $message);
    }

    private function makeException(int $status, string $errorMessage): EHealthResponseException
    {
        $psrResponse = new GuzzleResponse(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['error' => ['message' => $errorMessage]]),
        );

        return new EHealthResponseException(new Response($psrResponse));
    }
}
