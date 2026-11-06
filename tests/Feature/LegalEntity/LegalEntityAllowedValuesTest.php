<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Livewire\LegalEntity\CreateLegalEntity;
use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;
use App\Rules\InDictionary;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class LegalEntityAllowedValuesTest extends TestCase
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
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000088_create_users_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }
        new ReflectionProperty(InDictionary::class, 'dictionaryCache')->setValue(null, []);
        Http::preventStrayRequests();
        $this->mockBasicDictionaries([
            'LICENSE_TYPE' => ['MSP' => 'Медична практика', 'PHARMACY' => 'Аптека'],
            'POSITION' => array_fill_keys([...config('ehealth.employee_type.OWNER.position'), 'P7', 'P240'], 'Посада'),
            'DOCUMENT_TYPE' => array_fill_keys([
                ...config('ehealth.employee_identity_document_types'), 'TAX_ID', 'BIRTH_CERTIFICATE',
            ], 'Документ'),
            'ACCREDITATION_CATEGORY' => [
                'FIRST' => 'Перша', 'SECOND' => 'Друга', 'HIGHEST' => 'Вища', 'NO_ACCREDITATION' => 'Без акредитації',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(InDictionary::class, 'dictionaryCache')->setValue(null, []);
        parent::tearDown();
    }

    #[DataProvider('invalidOwnerValues')]
    public function test_invalid_owner_values_are_rejected_by_the_server(string $class, string $field, mixed $value): void
    {
        $component = $this->screen($class);
        data_set($component->legalEntityForm->owner, $field, $value);
        // Public UI options are not an authority for server-side validation.
        $component->dictionaries = ['POSITION' => ['P7' => 'Підмінена посада'], 'DOCUMENT_TYPE' => ['TAX_ID' => 'ІПН']];

        $this->assertInvalid($component->legalEntityForm, 'rulesForOwner', 'owner.' . $field);
    }

    public static function invalidOwnerValues(): array
    {
        $cases = [];
        foreach ([CreateLegalEntity::class, EditLegalEntity::class] as $class) {
            foreach ([
                'general position not allowed for owner' => ['position', 'P7'],
                'pharmacy-only position not in OWNER_POSITIONS' => ['position', 'P240'],
                'unknown position' => ['position', 'UNKNOWN'],
                'tax id as the only document' => ['documents.0.type', 'TAX_ID'],
                'birth certificate' => ['documents.0.type', 'BIRTH_CERTIFICATE'],
                'residence permit stays excluded' => ['documents.0.type', 'PERMANENT_RESIDENCE_PERMIT'],
                'unknown document' => ['documents.0.type', 'UNKNOWN'],
                'unknown gender' => ['gender', 'UNKNOWN'],
                'wrong gender case' => ['gender', 'male'],
            ] as $name => [$field, $value]) {
                $cases[$class . ' ' . $name] = [$class, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('validOwnerDocuments')]
    public function test_supported_identity_documents_remain_allowed_with_a_tax_id(string $class, string $type, string $number): void
    {
        $component = $this->screen($class);
        $component->legalEntityForm->owner['documents'] = [['type' => $type, 'number' => $number]];

        $component->legalEntityForm->rulesForOwner();

        $this->assertFalse($component->getErrorBag()->any());
        $this->assertContains($type, $component->ownerDocumentTypes());
    }

    public static function validOwnerDocuments(): array
    {
        $cases = [];
        foreach ([CreateLegalEntity::class, EditLegalEntity::class] as $class) {
            foreach ([
                'PASSPORT' => 'АА123456',
                'NATIONAL_ID' => '123456789',
                'REFUGEE_CERTIFICATE' => 'АА123456',
                'COMPLEMENTARY_PROTECTION_CERTIFICATE' => 'АА123456',
                'TEMPORARY_CERTIFICATE' => 'АА123456',
                'TEMPORARY_PASSPORT' => 'AB123456',
            ] as $type => $number) {
                $cases[$class . ' ' . $type] = [$class, $type, $number];
            }
        }

        return $cases;
    }

    #[DataProvider('components')]
    public function test_configuration_is_applied_to_both_the_ui_and_server_rules(string $class): void
    {
        config(['ehealth.employee_type.OWNER.position' => ['P7']]);
        $component = $this->screen($class);
        new ReflectionMethod(LegalEntityComponent::class, 'getDictionary')->invoke($component);
        new ReflectionMethod(LegalEntityComponent::class, 'getOwnerFields')->invoke($component);
        $component->legalEntityForm->owner['position'] = 'P7';
        $component->legalEntityForm->owner['gender'] = 'FEMALE';

        $component->legalEntityForm->rulesForOwner();

        $this->assertSame(['P7'], array_keys($component->dictionaries['POSITION']));
        $this->assertSame($component->ownerDocumentTypes(), array_values(array_intersect(
            $component->ownerDocumentTypes(),
            array_keys($component->dictionaries['DOCUMENT_TYPE'])
        )));
        $this->assertArrayNotHasKey('PERMANENT_RESIDENCE_PERMIT', $component->dictionaries['DOCUMENT_TYPE']);
        $this->assertArrayNotHasKey('TAX_ID', $component->dictionaries['DOCUMENT_TYPE']);
    }

    #[DataProvider('components')]
    public function test_accreditation_category_is_validated_only_when_the_section_is_used(string $class): void
    {
        $component = $this->screen($class);
        $form = $component->legalEntityForm;
        $form->accreditationShow = true;
        $form->accreditation = [
            'category' => 'UNKNOWN', 'orderNo' => '123', 'orderDate' => '01.01.2025',
            'issuedDate' => null, 'expiryDate' => null,
        ];
        $this->assertInvalid($form, 'rulesForAccreditation', 'accreditation.category');
        foreach (['FIRST', 'SECOND', 'HIGHEST', 'NO_ACCREDITATION'] as $category) {
            $form->accreditation['category'] = $category;
            $form->rulesForAccreditation();
        }
        $form->accreditationShow = false;
        $form->accreditation['category'] = 'IGNORED_HISTORICAL_VALUE';
        $form->rulesForAccreditation();

        $this->assertSame('IGNORED_HISTORICAL_VALUE', $form->accreditation['category']);
    }

    #[DataProvider('components')]
    public function test_only_supported_legal_entity_types_pass_the_first_step(string $class): void
    {
        $component = $this->screen($class);
        $component->legalEntityTypes = ['MADE_UP' => 'Підмінений тип'];
        foreach (['', 'MADE_UP', 'NHS', 'MIS', 'MSP', 'MSP_LIMITED'] as $type) {
            $component->legalEntityForm->type = $type;
            $this->assertInvalid($component->legalEntityForm, 'rulesForEdrpou', 'type');
        }
        foreach (['PRIMARY_CARE', 'OUTPATIENT', 'EMERGENCY', 'PHARMACY'] as $type) {
            $component->legalEntityForm->type = $type;
            $validated = $component->legalEntityForm->rulesForEdrpou();
            $this->assertSame($type, $validated['type']);
        }
    }

    public static function components(): array
    {
        return ['create' => [CreateLegalEntity::class], 'edit' => [EditLegalEntity::class]];
    }

    public function test_final_validation_reports_a_type_error_on_the_first_step(): void
    {
        $component = new CreateLegalEntity();
        $form = Mockery::mock(LegalEntitiesForms::class, [$component, 'legalEntityForm'])->makePartial();
        $form->shouldReceive('allFieldsValidate')->once()->andThrow(ValidationException::withMessages([
            'legalEntityForm.type' => 'Непідтримуваний тип закладу',
        ]));
        $component->legalEntityForm = $form;

        $valid = new ReflectionMethod(CreateLegalEntity::class, 'validationRequest')->invoke($component);

        $this->assertFalse($valid);
        $this->assertSame(__('forms.edrpou'), $component->validationErrorStep);
        Http::assertNothingSent();
    }

    private function assertInvalid(LegalEntitiesForms $form, string $method, string $field): void
    {
        try {
            $form->{$method}();
            $this->fail('An unsupported value must fail validation before signing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legalEntityForm.' . $field, $exception->errors());
        }
        Http::assertNothingSent();
    }

    private function screen(string $class): LegalEntityComponent
    {
        $component = new $class();
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->legalEntityForm->edrpou = '12345678';
        $component->legalEntityForm->owner = [
            'lastName' => 'Керівник', 'firstName' => 'Іван', 'birthDate' => '01.01.1980',
            'gender' => 'MALE', 'email' => 'owner@example.test', 'position' => 'P1',
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            'documents' => [['type' => 'NATIONAL_ID', 'number' => '123456789']],
            'noTaxId' => false, 'taxId' => '1234567890',
        ];

        return $component;
    }
}
