<?php

declare(strict_types=1);

namespace Tests\Feature\EHealth;

use App\Exceptions\EHealth\EHealthResponseException;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests that EHealthResponseException::handle() shows the correct
 * informational message when the API returns 403 "Party is not verified".
 *
 * The fix lives in the base exception class so that every ->handle() call
 * across the project (Declarations, Encounters, Patients, Divisions, etc.)
 * automatically benefits — without touching individual Livewire components.
 */
class EHealthResponseExceptionPartyNotVerifiedTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isPartyNotVerified()
    // -------------------------------------------------------------------------

    #[Test]
    public function is_party_not_verified_returns_true_for_403_with_matching_message(): void
    {
        $exception = $this->makeException(403, 'Access denied. Party is not verified');

        $this->assertTrue($exception->isPartyNotVerified());
    }

    #[Test]
    #[DataProvider('nonPartyNotVerifiedProvider')]
    public function is_party_not_verified_returns_false_for_other_cases(int $status, string $message): void
    {
        $exception = $this->makeException($status, $message);

        $this->assertFalse($exception->isPartyNotVerified());
    }

    /** @return array<string, array{int, string}> */
    public static function nonPartyNotVerifiedProvider(): array
    {
        return [
            '403 different message' => [403, 'Access denied. Insufficient permissions.'],
            '422 party message' => [422, 'Access denied. Party is not verified'],
            '200 party message' => [200, 'Access denied. Party is not verified'],
            '500 empty message' => [500, ''],
        ];
    }

    // -------------------------------------------------------------------------
    // handle() — party not verified path
    // -------------------------------------------------------------------------

    #[Test]
    public function handle_flashes_party_not_verified_translation_on_403(): void
    {
        $exception = $this->makeException(403, 'Access denied. Party is not verified');

        $exception->handle('Test log message');

        $this->assertEquals(
            __('errors.ehealth.messages.party_not_verified'),
            Session::get('error')
        );
    }

    #[Test]
    public function handle_does_not_use_party_not_verified_translation_for_other_403_messages(): void
    {
        $exception = $this->makeException(403, 'Access denied. Insufficient permissions.');

        $exception->handle('Test log message');

        $this->assertNotEquals(
            __('errors.ehealth.messages.party_not_verified'),
            Session::get('error')
        );
    }

    #[Test]
    public function handle_always_flashes_party_not_verified_even_when_caller_provides_flash_message(): void
    {
        $exception = $this->makeException(403, 'Access denied. Party is not verified');

        $exception->handle('Test log message', 'Custom override message from caller');

        $this->assertEquals(
            __('errors.ehealth.messages.party_not_verified'),
            Session::get('error')
        );
    }

    // -------------------------------------------------------------------------
    // Translation key content
    // -------------------------------------------------------------------------

    #[Test]
    public function party_not_verified_translation_contains_all_required_sections(): void
    {
        $message = __('errors.ehealth.messages.party_not_verified');

        $this->assertStringContainsString('Увага!', $message);
        $this->assertStringContainsString('Працівника не верифіковано', $message);
        $this->assertStringContainsString('ДПС або ДРАЦСГ', $message);
        $this->assertStringContainsString('відділу кадрів', $message);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

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
