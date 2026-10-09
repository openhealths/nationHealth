<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\EHealth\EHealthResponseException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class EHealthInactiveLegalEntityTest extends TestCase
{
    public function test_inactive_token_legal_entity_is_actionable_in_production_and_prefixed_messages(): void
    {
        config(['app.debug' => false, 'app.env' => 'production']);
        $exception = $this->exception('client_id refers to legal entity that is not active');
        $expected = __('errors.ehealth.messages.legal_entity_not_active');

        $this->assertNotSame('errors.ehealth.messages.legal_entity_not_active', $expected);
        $this->assertSame($expected, $exception->getMessage());
        $exception->handle('Referral create failed', 'Не вдалося створити направлення: '.$exception->getMessage());
        $this->assertSame('Не вдалося створити направлення: '.$expected, session('error'));
        $this->assertSame(409, $exception->getCode());
    }

    public function test_unknown_conflicts_keep_the_production_fallback(): void
    {
        config(['app.debug' => false, 'app.env' => 'production']);

        $this->assertSame(__('care-plan.unexpected_error'), $this->exception('Unknown conflict')->getMessage());
    }

    private function exception(string $message): EHealthResponseException
    {
        return new EHealthResponseException(new Response(new PsrResponse(
            409,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR)
        )));
    }
}
