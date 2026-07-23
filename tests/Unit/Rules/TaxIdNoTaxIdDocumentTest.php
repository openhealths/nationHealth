<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\TaxId;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxIdNoTaxIdDocumentTest extends TestCase
{
    #[Test]
    public function accepts_permanent_residence_permit_when_no_tax_id(): void
    {
        $validator = Validator::make(
            [
                'party' => [
                    'noTaxId' => true,
                    'taxId' => 'АА123456',
                    'email' => 'permit@example.com',
                ],
                'documents' => [
                    [
                        'type' => 'PERMANENT_RESIDENCE_PERMIT',
                        'number' => 'АА123456',
                    ],
                ],
            ],
            [
                'party.taxId' => ['required', 'string', new TaxId()],
            ]
        );

        $this->assertTrue($validator->passes(), (string) $validator->errors());
    }

    #[Test]
    public function rejects_no_tax_id_without_qualifying_document(): void
    {
        $validator = Validator::make(
            [
                'party' => [
                    'noTaxId' => true,
                    'taxId' => 'АА123456',
                    'email' => 'none@example.com',
                ],
                'documents' => [
                    [
                        'type' => 'TEMPORARY_PASSPORT',
                        'number' => 'AB123456',
                    ],
                ],
            ],
            [
                'party.taxId' => ['required', 'string', new TaxId()],
            ]
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            'Посвідка на постійне проживання',
            $validator->errors()->first('party.taxId')
        );
    }
}
