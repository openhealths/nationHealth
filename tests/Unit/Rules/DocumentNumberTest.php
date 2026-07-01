<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\DocumentNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentNumberTest extends TestCase
{
    #[DataProvider('invalidDocumentNumbersProvider')]
    public function test_it_returns_type_specific_format_message_for_invalid_numbers(string $type, string $number, string $expectedFragment): void
    {
        $validator = Validator::make(
            ['number' => $number],
            ['number' => [new DocumentNumber($type)]]
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString($expectedFragment, $validator->errors()->first('number'));
    }

    #[DataProvider('validDocumentNumbersProvider')]
    public function test_it_accepts_valid_document_numbers(string $type, string $number): void
    {
        $validator = Validator::make(
            ['number' => $number],
            ['number' => [new DocumentNumber($type)]]
        );

        $this->assertFalse($validator->fails());
    }

    public static function invalidDocumentNumbersProvider(): array
    {
        return [
            'passport latin letters' => ['PASSPORT', 'AA123456', 'Паспорт'],
            'passport with spaces' => ['PASSPORT', 'AA 123456', 'Паспорт'],
            'passport nine digits is national id format' => ['PASSPORT', '123456789', 'Паспорт'],
            'national id too short' => ['NATIONAL_ID', '12345', '9 цифр'],
            'refugee wrong format' => ['REFUGEE_CERTIFICATE', '1234567890', 'Посвідчення біженця'],
        ];
    }

    public static function validDocumentNumbersProvider(): array
    {
        return [
            'passport cyrillic series' => ['PASSPORT', 'АА123456'],
            'national id' => ['NATIONAL_ID', '123456789'],
            'refugee certificate' => ['REFUGEE_CERTIFICATE', 'АА123456'],
            'permanent residence permit' => ['PERMANENT_RESIDENCE_PERMIT', 'АА123456'],
        ];
    }
}
