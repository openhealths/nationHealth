<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\EHealth\EHealthValidationException;
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
            $exception = new EHealthValidationException(['error' => $error]);
            foreach ([$exception->getFormattedMessage(), $exception->getTranslatedMessage()] as $result) {
                $this->assertStringContainsString($translation, $result);
                $this->assertStringNotContainsString($message, $result);
            }
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
