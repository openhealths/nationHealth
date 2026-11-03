<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\EHealth;
use App\Livewire\Employee\EmployeeCreate;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesEmployeeDictionaries;
use Tests\TestCase;

class EmployeeWorkingExperienceSigningTest extends TestCase
{
    use DatabaseTransactions;
    use FakesEmployeeDictionaries;

    public static function experienceValues(): array
    {
        return [
            'zero' => ['HR', 0, true, 0],
            'zero from input' => ['HR', '0', true, 0],
            'owner zero' => ['OWNER', 0, true, 0],
            'owner zero from input' => ['OWNER', '0', true, 0],
            'optional' => ['HR', null, true, null],
            'negative' => ['HR', -1, false, null],
            'owner negative' => ['OWNER', -1, false, null],
            'fractional' => ['HR', 0.5, false, null],
        ];
    }

    #[DataProvider('experienceValues')]
    public function test_preparing_for_signing_validates_experience_and_preserves_it_in_the_api_payload(
        string $employeeType,
        int|float|string|null $experience,
        bool $valid,
        ?int $expected
    ): void {
        $this->fakeEmployeeDictionaries();
        Cache::put('knedp_certificate_authority', [], 60);
        $type = LegalEntityType::firstOrCreate(['name' => 'OUTPATIENT']);
        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $type->id, 'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);
        $this->actingAs(User::create(['email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]));
        Gate::before(fn (): bool => true);
        $count = EmployeeRequest::count();
        $component = Livewire::test(EmployeeCreate::class, ['legalEntity' => $legalEntity])
            ->set('form.employeeType', $employeeType)
            ->set('form.position', config('ehealth.employee_type.'.$employeeType.'.position')[0])
            ->set('form.startDate', now()->format(config('app.date_format')))
            ->set('form.party', [
                'firstName' => 'Іван', 'lastName' => 'Тестовий', 'secondName' => null,
                'birthDate' => '01.01.1990', 'gender' => 'MALE', 'taxId' => '1234567890', 'noTaxId' => false,
                'email' => 'experience@example.test', 'workingExperience' => $experience, 'aboutMyself' => null,
                'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
            ])
            ->set('form.documents', [[
                'type' => 'NATIONAL_ID', 'number' => '123456789', 'issuedBy' => 'Орган', 'issuedAt' => '01.01.2020',
            ]])
            ->call('prepareForSigning');

        if (!$valid) {
            $component->assertHasErrors('form.party.workingExperience')
                ->assertNotDispatched('open-request-preview-modal');
            $this->assertSame($count, EmployeeRequest::count());

            return;
        }

        $component->assertHasNoErrors()->assertDispatched('open-request-preview-modal');
        $request = EmployeeRequest::findOrFail($component->get('employeeRequestId'));
        $this->assertSame($experience, $request->revision->data['party']['working_experience']);
        $payload = EHealth::employeeRequest()->schemaCreate($request->revision->data)['employee_request']['party'];
        if ($expected === null) {
            $this->assertArrayNotHasKey('working_experience', $payload);
        } else {
            $this->assertSame($expected, $payload['working_experience']);
        }
    }
}
