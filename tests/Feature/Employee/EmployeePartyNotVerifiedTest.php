<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Exceptions\EHealth\EHealthResponseException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePartyNotVerifiedTest extends TestCase
{
    /**
     * Verifies that the handleEHealthResponseException method returns
     * the correct localized message when the eHealth API returns
     * HTTP 403 with "Party is not verified" in the error body.
     */
    #[Test]
    public function it_returns_party_not_verified_message_on_403_with_party_not_verified_body(): void
    {
        $responseBody = [
            'error' => [
                'type' => 'forbidden',
                'message' => 'Access denied. Party is not verified',
            ],
        ];

        $mockResponse = $this->buildMockEHealthResponse(403, $responseBody);
        $exception = new EHealthResponseException($mockResponse);

        // The exception code should be the HTTP status
        $this->assertSame(403, $exception->getCode());

        // The details should contain the error body
        $this->assertSame($responseBody, $exception->getDetails());

        $apiErrorMessage = data_get($exception->getDetails(), 'error.message', '');
        $this->assertStringContainsString('Party is not verified', $apiErrorMessage);
    }

    /**
     * Verifies that a 403 with a different error message does NOT
     * trigger the party_not_verified translation key.
     */
    #[Test]
    public function it_does_not_use_party_not_verified_message_for_other_403_errors(): void
    {
        $responseBody = [
            'error' => [
                'type' => 'forbidden',
                'message' => 'Access denied. Insufficient permissions.',
            ],
        ];

        $mockResponse = $this->buildMockEHealthResponse(403, $responseBody);
        $exception = new EHealthResponseException($mockResponse);

        $apiErrorMessage = data_get($exception->getDetails(), 'error.message', '');
        $this->assertStringNotContainsString('Party is not verified', $apiErrorMessage);
    }

    /**
     * Verifies that the party_not_verified translation key exists and
     * contains the required informational message.
     */
    #[Test]
    public function it_has_correct_party_not_verified_translation(): void
    {
        $message = __('errors.ehealth.messages.party_not_verified');

        $this->assertNotEmpty($message);
        $this->assertStringContainsString('Увага!', $message);
        $this->assertStringContainsString('Працівника не верифіковано', $message);
        $this->assertStringContainsString('ДПС або ДРАЦСГ', $message);
        $this->assertStringContainsString('відділу кадрів', $message);
    }

    /**
     * Builds a mock Illuminate HTTP Response with the given status and body.
     *
     * @param  int  $status
     * @param  array<string, mixed>  $body
     */
    private function buildMockEHealthResponse(int $status, array $body): Response
    {
        $psrResponse = new \GuzzleHttp\Psr7\Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
        );

        return new Response(new \GuzzleHttp\Psr7\Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
        ));
    }
}
