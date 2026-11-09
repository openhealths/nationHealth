<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Classes\eHealth\Api\ServiceRequest;
use App\Classes\eHealth\Errors\BaseErrorTranslator;
use App\Classes\eHealth\Errors\CompositionErrorTranslator;
use App\Classes\eHealth\Errors\ServiceRequestErrorTranslator;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalErrorTranslationsTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_top_level_and_field_errors_are_translated(string $message, string $key): void
    {
        app()->setLocale('uk');
        $translation = __($key);
        $this->assertNotSame($key, $translation);

        foreach ([
            ['message' => $message],
            ['type' => 'validation_failed', 'invalid' => [[
                'entry' => '$.author', 'rules' => [['description' => $message]],
            ]]],
        ] as $error) {
            $translator = str_starts_with($key, 'errors.ehealth.rules.')
                ? new CompositionErrorTranslator()
                : new BaseErrorTranslator();
            $exception = new EHealthValidationException(['error' => $error], $translator);
            foreach ([$exception->getFormattedMessage(), $exception->getTranslatedMessage()] as $result) {
                $this->assertStringContainsString($translation, $result);
                $this->assertStringNotContainsString($message, $result);
            }
        }
    }

    public function test_successful_http_receipt_keeps_the_api_error_translator(): void
    {
        app()->setLocale('uk');
        $error = ['type' => 'validation_failed', 'invalid' => [[
            'entry' => '$.completed_with.identifier.type',
            'rules' => [['description' => 'Not allowed for this category']],
        ]]];
        Http::fake(['*' => Http::response(['data' => ['status' => 'failed', 'error' => $error]])]);
        $factory = Http::getFacadeRoot();
        $api = new ServiceRequest($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();

        $response = $api->complete('synthetic-referral');
        $this->assertInstanceOf(ServiceRequestErrorTranslator::class, $response->errorTranslator());
        $exception = new EHealthValidationException($response->validate(), $response->errorTranslator());
        foreach ([$exception->getFormattedMessage(), $exception->getTranslatedMessage()] as $message) {
            $this->assertStringContainsString(__('referrals.messages.completion_type_not_allowed'), $message);
            $this->assertStringNotContainsString('Not allowed for this category', $message);
        }
    }

    public static function messages(): array
    {
        return [
            ['Invalid author employee type', 'errors.ehealth.messages.invalid_author_employee_type'],
            ['Same end date for new and existing composition', 'errors.ehealth.rules.1103'],
            ['1103: Same end date for new and existing composition', 'errors.ehealth.rules.1103'],
            ['Encounter type is not allowed for composition type', 'errors.ehealth.rules.1038'],
            ['1038: Encounter type is not allowed for composition type', 'errors.ehealth.rules.1038'],
            ["Can't get phone number", 'errors.ehealth.rules.1017'],
            ["1017: Can't get phone number", 'errors.ehealth.rules.1017'],
        ];
    }
}
