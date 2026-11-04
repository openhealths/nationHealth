<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Livewire\LegalEntity\CreateLegalEntity;
use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;
use App\Rules\TaxId;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class LegalEntityNoTaxIdValidationTest extends TestCase
{
    use MocksBasicDictionaries;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'app.date_format' => 'd.m.Y',
        ]);
        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000088_create_users_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }
        Http::preventStrayRequests();
        $this->mockBasicDictionaries([
            'DOCUMENT_TYPE' => [
                'PASSPORT' => 'Паспорт',
                'NATIONAL_ID' => 'ID-картка',
                'REFUGEE_CERTIFICATE' => 'Посвідчення біженця',
                'PERMANENT_RESIDENCE_PERMIT' => 'Посвідка на постійне проживання',
            ],
            'LE_CONSENT_TEXT' => ['CONSENT' => 'Підтверджую достовірність даних'],
        ]);
    }

    #[DataProvider('unsupportedOwnerDocuments')]
    public function test_owner_validation_requires_a_numbered_passport_or_id_card(string $componentClass, array $documents): void
    {
        $component = $this->legalEntityComponent($componentClass, $documents);

        try {
            $component->legalEntityForm->rulesForOwner();
            $this->fail('The owner must have a supported identity document before signing.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('forms.owner_no_tax_id_document_required')],
                $exception->errors()['legalEntityForm.owner.noTaxId'] ?? []
            );
        }

        Http::assertNothingSent();
    }

    #[DataProvider('unsupportedOwnerDocuments')]
    public function test_signing_stops_with_a_validation_error_when_a_supported_document_is_missing(string $componentClass, array $documents): void
    {
        $component = $this->legalEntityComponent($componentClass, $documents);

        try {
            new ReflectionMethod(LegalEntityComponent::class, 'signLegalEntity')->invoke($component);
            $this->fail('Signing must not start without a supported document.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('forms.owner_no_tax_id_document_required')],
                $exception->errors()['legalEntityForm.owner.noTaxId'] ?? []
            );
        }

        Http::assertNothingSent();
    }

    public static function unsupportedOwnerDocuments(): array
    {
        $cases = [];
        foreach ([CreateLegalEntity::class, EditLegalEntity::class] as $component) {
            foreach ([
                'no documents' => [],
                'refugee certificate only' => [['type' => 'REFUGEE_CERTIFICATE', 'number' => 'АА123456']],
                'residence permit only' => [['type' => 'PERMANENT_RESIDENCE_PERMIT', 'number' => 'АА123456']],
                'passport without number' => [['type' => 'PASSPORT']],
                'empty id number' => [['type' => 'NATIONAL_ID', 'number' => '']],
                'blank passport number' => [['type' => 'PASSPORT', 'number' => '   ']],
            ] as $name => $documents) {
                $cases[$component . ' ' . $name] = [$component, $documents];
            }
        }

        return $cases;
    }

    #[DataProvider('supportedOwnerDocuments')]
    public function test_validated_owner_document_number_is_used_in_the_request(string $componentClass, array $documents, string $expectedNumber): void
    {
        $component = $this->legalEntityComponent($componentClass, $documents);
        $component->legalEntityForm->rulesForOwner();

        $request = new ReflectionMethod(LegalEntityComponent::class, 'prepareDataForRequest')->invoke(
            $component,
            $component->legalEntityForm->toArray()
        );

        $this->assertSame($expectedNumber, $request['owner']['tax_id']);
        $this->assertTrue($request['owner']['no_tax_id']);
    }

    public static function supportedOwnerDocuments(): array
    {
        $cases = [];
        foreach ([CreateLegalEntity::class, EditLegalEntity::class] as $component) {
            foreach ([
                'passport' => [[['type' => 'PASSPORT', 'number' => 'АА123456']], 'АА123456'],
                'id card' => [[['type' => 'NATIONAL_ID', 'number' => '123456789']], '123456789'],
                'refugee certificate before passport' => [[
                    ['type' => 'REFUGEE_CERTIFICATE', 'number' => 'ББ654321'],
                    ['type' => 'PASSPORT', 'number' => 'АА123456'],
                ], 'АА123456'],
            ] as $name => [$documents, $number]) {
                $cases[$component . ' ' . $name] = [$component, $documents, $number];
            }
        }

        return $cases;
    }

    #[DataProvider('employeeDocuments')]
    public function test_employee_validation_still_accepts_other_configured_identity_documents(string $type): void
    {
        $validator = Validator::make([
            'party' => ['noTaxId' => true, 'taxId' => 'АА123456', 'email' => 'employee@example.test'],
            'documents' => [['type' => $type, 'number' => 'АА123456']],
        ], ['party.taxId' => ['required', 'string', new TaxId()]]);

        $this->assertTrue($validator->passes(), (string) $validator->errors());
    }

    public static function employeeDocuments(): array
    {
        return [
            'refugee certificate' => ['REFUGEE_CERTIFICATE'],
            'residence permit' => ['PERMANENT_RESIDENCE_PERMIT'],
        ];
    }

    private function legalEntityComponent(string $class, array $documents): LegalEntityComponent
    {
        $component = new $class();
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->legalEntityForm->owner = [
            'lastName' => 'Керівник',
            'firstName' => 'Іван',
            'birthDate' => '01.01.1980',
            'gender' => 'MALE',
            'email' => 'owner@example.test',
            'position' => 'P1',
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'documents' => $documents,
            'noTaxId' => true,
            'taxId' => '',
        ];
        $component->legalEntityForm->license = [
            'type' => 'MSP',
            'issuedBy' => 'МОЗ',
            'issuedDate' => '01.01.2025',
            'activeFromDate' => '01.01.2025',
            'orderNo' => '123',
        ];

        return $component;
    }
}
