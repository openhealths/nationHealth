<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Employee\EmployeeComponent;
use App\Models\LegalEntity;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\Forms\EmployeeForm;
use App\Models\Employee\Employee;
use App\Models\Relations\Party;
use App\Rules\UniquePassportRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class EmployeeTesterRemarksTest extends TestCase
{
    #[Test]
    public function position_label_uses_dictionary_and_falls_back_to_free_text(): void
    {
        $component = $this->employeeScreen();
        $component->dictionaries = [
            'POSITION' => [
                'P83' => 'Лікар-хірург',
                'P17' => 'Лаборант (медицина)',
            ],
        ];
        $component->form->position = 'P83';

        $this->assertSame('Лікар-хірург', $component->positionDisplayLabel());

        $component->form->position = 'Лаборант-імунолог';

        $this->assertSame('Лаборант-імунолог', $component->positionDisplayLabel());
    }

    #[Test]
    public function position_options_are_not_rendered_by_alpine_inside_the_select(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/position.blade.php'));
        $show = file_get_contents(resource_path('views/livewire/employee/employee-show.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertNotFalse($show);
        $this->assertStringNotContainsString('x-for', $blade);
        $this->assertStringContainsString('positionDisplayLabel()', $blade);
        $this->assertStringContainsString('positionIsCustom', $blade);
        $this->assertStringContainsString('forms.employee_request_identifier', $show);
    }

    #[Test]
    public function hr_accepts_dictionary_or_free_text_and_doctor_accepts_only_dictionary(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 1;
        app()->instance('legalEntity', $legalEntity);

        $form = $this->form();

        $form->employeeType = 'HR';
        $form->positionIsCustom = false;
        $dictionaryRules = $this->positionRules($form);
        $this->assertTrue($this->rulesContainIn($dictionaryRules));
        $this->assertFalse(Validator::make(['position' => 'P14'], ['position' => $dictionaryRules])->fails());

        $form->positionIsCustom = true;
        $freeTextRules = $this->positionRules($form);
        $this->assertFalse($this->rulesContainIn($freeTextRules));
        $this->assertFalse(Validator::make(
            ['position' => 'Менеджер з персоналу'],
            ['position' => $freeTextRules]
        )->fails());

        $form->employeeType = 'DOCTOR';
        $form->positionIsCustom = true;
        $doctorRules = $this->positionRules($form);
        $this->assertTrue($this->rulesContainIn($doctorRules));
        $this->assertTrue(Validator::make(['position' => 'Лікар-хірург'], ['position' => $doctorRules])->fails());
        $this->assertFalse(Validator::make(['position' => 'P8'], ['position' => $doctorRules])->fails());
    }

    #[Test]
    public function create_employee_email_field_is_marked_required(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/party.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertMatchesRegularExpression(
            '/id="email"[\s\S]*?required/',
            $blade
        );
    }

    #[Test]
    public function email_must_be_ascii_and_duplicate_document_types_are_rejected(): void
    {
        $emailRules = (new ReflectionMethod(EmployeeForm::class, 'partyRules'))->invoke($this->form());
        $this->assertContains('ascii', $emailRules['party.email']);

        $cyrillic = Validator::make(
            ['email' => 'ната@yahoo.com'],
            ['email' => ['required', 'email', 'ascii']]
        );
        $this->assertTrue($cyrillic->fails());

        $latin = Validator::make(
            ['email' => 'nata@yahoo.com'],
            ['email' => ['required', 'email', 'ascii']]
        );
        $this->assertFalse($latin->fails());

        $duplicate = Validator::make(
            ['documents' => [
                ['type' => 'PASSPORT', 'number' => 'АА123456'],
                ['type' => 'PASSPORT', 'number' => 'ВВ654321'],
            ]],
            ['documents' => [new UniquePassportRule()]]
        );
        $this->assertTrue($duplicate->fails());
        $this->assertStringContainsString('Дублювання типу документа', (string) $duplicate->errors()->first());
    }

    #[Test]
    public function document_issue_date_cannot_be_before_birth_date(): void
    {
        $form = $this->form();
        $form->party['birthDate'] = '23.02.2001';
        $rules = (new ReflectionMethod(EmployeeForm::class, 'documentsRules'))->invoke($form);

        $tooEarly = Validator::make(
            ['documents' => [[
                'type' => 'PASSPORT',
                'number' => 'АА123456',
                'issuedBy' => 'ГУ',
                'issuedAt' => '01.01.2000',
            ]]],
            [
                'documents' => $rules['documents'],
                'documents.*.type' => $rules['documents.*.type'],
                'documents.*.number' => $rules['documents.*.number'],
                'documents.*.issuedBy' => $rules['documents.*.issuedBy'],
                'documents.*.issuedAt' => $rules['documents.*.issuedAt'],
            ]
        );

        $this->assertTrue($tooEarly->fails());
        $this->assertStringContainsString(
            'дату народження',
            (string) $tooEarly->errors()->first('documents.0.issuedAt')
        );

        $form->documents = [[
            'type' => 'PASSPORT',
            'number' => 'АА123456',
        ]];
        $later = Validator::make(
            ['documents' => [[
                'type' => 'PASSPORT',
                'number' => 'АА123456',
                'issuedBy' => 'ГУ',
                'issuedAt' => '01.03.2018',
            ]]],
            [
                'documents' => $rules['documents'],
                'documents.*.issuedAt' => $rules['documents.*.issuedAt'],
            ]
        );

        $this->assertFalse($later->fails(), (string) $later->errors());
    }

    #[Test]
    public function locked_party_without_tax_id_keeps_the_document_number(): void
    {
        $party = new Party();
        $party->noTaxId = true;
        $party->taxId = 'АА000000';

        $employee = new Employee();
        $employee->setRelation('party', $party);

        $component = new EmployeeCreate();
        $component->isPartyDataPartiallyLocked = true;
        $property = new ReflectionProperty(EmployeeCreate::class, 'employee');
        $property->setAccessible(true);
        $property->setValue($component, $employee);

        $method = new ReflectionMethod(EmployeeCreate::class, 'mapRevisionData');
        $method->setAccessible(true);
        $revision = $method->invoke($component, [
            'first_name' => 'Олена',
            'last_name' => 'Іванова',
            'tax_id' => '1234567890',
            'no_tax_id' => false,
            'documents' => [
                ['type' => 'PASSPORT', 'number' => 'СН123456'],
            ],
            'phones' => [
                ['type' => 'MOBILE', 'number' => '+380501112233'],
            ],
        ]);

        $this->assertTrue($revision['party']['no_tax_id']);
        $this->assertSame('СН123456', $revision['party']['tax_id']);
    }

    #[Test]
    public function locked_party_with_tax_id_cannot_be_replaced(): void
    {
        $party = new Party();
        $party->noTaxId = false;
        $party->taxId = '1234567890';

        $employee = new Employee();
        $employee->setRelation('party', $party);

        $component = new EmployeeCreate();
        $component->isPartyDataPartiallyLocked = true;
        $property = new ReflectionProperty(EmployeeCreate::class, 'employee');
        $property->setAccessible(true);
        $property->setValue($component, $employee);

        $method = new ReflectionMethod(EmployeeCreate::class, 'mapRevisionData');
        $method->setAccessible(true);
        $revision = $method->invoke($component, [
            'tax_id' => '0987654321',
            'no_tax_id' => true,
            'documents' => [],
        ]);

        $this->assertFalse($revision['party']['no_tax_id']);
        $this->assertSame('1234567890', $revision['party']['tax_id']);
    }

    #[Test]
    public function ehealth_employee_validation_messages_are_ukrainian(): void
    {
        $email = new EHealthValidationException([
            'error' => [
                'type' => 'validation_failed',
                'invalid' => [[
                    'entry' => 'party.email',
                    'rules' => [[
                        'description' => 'expected "ната@yahoo.com" to be an email address',
                    ]],
                ]],
            ],
        ]);
        $duplicate = new EHealthValidationException([
            'error' => [
                'type' => 'validation_failed',
                'invalid' => [[
                    'entry' => 'party.documents.0.type',
                    'rules' => [[
                        'description' => 'No duplicate values.',
                    ]],
                ]],
            ],
        ]);
        $taxId = new EHealthValidationException([
            'error' => [
                'type' => 'validation_failed',
                'invalid' => [[
                    'entry' => 'party.tax_id',
                    'rules' => [[
                        'description' => 'invalid tax_id value',
                    ]],
                ]],
            ],
        ]);

        $emailMessage = $email->getTranslatedMessage();
        $this->assertStringContainsString('Email', $emailMessage);
        $this->assertStringContainsString('латиницею', $emailMessage);
        $this->assertStringNotContainsString('to be an email address', $emailMessage);

        $this->assertStringContainsString('не може повторюватись', $duplicate->getTranslatedMessage());
        $this->assertStringContainsString('Невірний номер РНОКПП', $taxId->getTranslatedMessage());
        $this->assertStringNotContainsString('invalid tax_id value', $taxId->getTranslatedMessage());
    }

    private function employeeScreen(): EmployeeComponent
    {
        $component = new class extends EmployeeComponent
        {
            public function render(): string
            {
                return '';
            }
        };
        $component->form = new EmployeeForm($component, 'form');

        return $component;
    }

    private function form(): EmployeeForm
    {
        return new EmployeeForm(new class extends Component
        {
            public array $dictionaries = [
                'EMPLOYEE_TYPE' => ['DOCTOR' => 'Лікар', 'HR' => 'HR'],
                'POSITION' => [],
                'DOCUMENT_TYPE' => ['PASSPORT' => 'Паспорт'],
            ];

            public function render(): string
            {
                return '';
            }
        }, 'form');
    }

    /**
     * @return list<mixed>
     */
    private function positionRules(EmployeeForm $form): array
    {
        $rules = (new ReflectionMethod(EmployeeForm::class, 'rootFieldsRules'))->invoke($form);

        return $rules['position'];
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function rulesContainIn(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule instanceof In) {
                return true;
            }
        }

        return false;
    }
}
