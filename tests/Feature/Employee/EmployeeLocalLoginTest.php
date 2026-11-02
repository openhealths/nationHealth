<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeePositionAdd;
use App\Livewire\Employee\EmployeeRequestEdit;
use App\Livewire\Employee\Forms\EmployeeForm;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Rules\TaxId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Livewire;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class EmployeeLocalLoginTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;
    private Party $party;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);
        $this->party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Коваленко',
            'tax_id' => '3212312312',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);
        $this->user = User::create([
            'email' => 'existing@example.com',
            'password' => 'unused-test-password',
            'party_id' => $this->party->id,
        ]);
        Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Коваленко Іван',
            'employee_type' => 'HR',
            'status' => Status::APPROVED,
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => '2026-01-01',
            'user_id' => $this->user->id,
            'party_id' => $this->party->id,
        ]);
        $this->actingAs($this->user);
        Gate::define('employee_request:write', fn () => true);
        Http::preventStrayRequests();
    }

    public static function environments(): array
    {
        return [
            'local' => ['local', true],
            'production' => ['production', false],
            'staging' => ['staging', false],
            'testing' => ['testing', false],
            'development is not local' => ['development', false],
        ];
    }

    #[Test]
    #[DataProvider('environments')]
    public function additionalLoginPolicyAndRenderedInputFollowEnvironment(string $environment, bool $allowed): void
    {
        $this->app['env'] = $environment;
        config(['app.debug' => true]);
        $this->assertSame($allowed, Gate::allows('createAdditionalLogin', EmployeeRequest::class));

        $view = Livewire::test(LocalLoginPositionView::class, [
            'legalEntity' => $this->legalEntity,
            'party' => $this->party,
        ]);
        if ($allowed) {
            $view->assertSeeHtml('list="party-user-emails"')->assertSee(__('forms.position_add_email_hint'));
        } else {
            $view->assertDontSeeHtml('list="party-user-emails"')
                ->assertDontSee(__('forms.position_add_email_hint'))
                ->assertSee(__('forms.select_user_email'));
        }
    }

    #[Test]
    public function localEnvironmentDoesNotGrantEmployeeWritePermission(): void
    {
        $this->app['env'] = 'local';
        Gate::define('employee_request:write', fn () => false);
        $this->assertFalse(Gate::allows('createAdditionalLogin', EmployeeRequest::class));
    }

    #[Test]
    #[DataProvider('environments')]
    public function duplicateTaxIdWithNewEmailIsAllowedOnlyLocally(string $environment, bool $allowed): void
    {
        $this->app['env'] = $environment;
        $validator = Validator::make([
            'party' => ['taxId' => $this->party->taxId, 'email' => 'new@example.com', 'noTaxId' => false],
        ], ['party.taxId' => ['required', new TaxId()]]);

        $this->assertSame($allowed, $validator->passes(), (string) $validator->errors());
    }

    #[Test]
    public function localDuplicateTaxIdStillRequiresANewEmail(): void
    {
        $this->app['env'] = 'local';
        $unrelatedUser = User::create(['email' => 'occupied@example.com', 'password' => 'unused']);

        foreach (['', $unrelatedUser->email] as $email) {
            $validator = Validator::make([
                'party' => ['taxId' => $this->party->taxId, 'email' => $email, 'noTaxId' => false],
            ], ['party.taxId' => ['required', new TaxId()]]);
            $this->assertFalse($validator->passes());
        }
    }

    #[Test]
    public function createCannotBypassProductionTaxIdCheckWithForgedPartyId(): void
    {
        $this->app['env'] = 'production';
        $component = new EmployeeCreate();
        $component->form = new EmployeeForm($component, 'form');
        $component->form->existingPartyId = $this->party->id;
        $component->form->party['taxId'] = $this->party->taxId;
        $component->form->party['email'] = 'new@example.com';
        $rules = $component->form->rulesForSave($component);

        $validator = Validator::make($component->form->all(), ['party.taxId' => $rules['party.taxId']]);

        $this->assertNull($component->form->existingPartyId);
        $this->assertFalse($validator->passes());
    }

    #[Test]
    public function localNewEmailIsPersistedInBothDraftAndRevision(): void
    {
        $this->app['env'] = 'local';
        $component = $this->positionComponent('new@example.com');
        $request = (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);

        $this->assertSame('new@example.com', $request->email);
        $this->assertSame('new@example.com', $request->revision->data['party']['email']);
        $this->assertNull($request->userId);
        $this->assertSame($this->party->id, $request->partyId);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    #[Test]
    #[DataProvider('environments')]
    public function productionKeepsExistingEmailWorking(string $environment, bool $allowed): void
    {
        $this->app['env'] = $environment;
        $component = $this->positionComponent($this->user->email);
        $request = (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);

        $this->assertSame($this->user->id, $request->userId);
        $this->assertSame($this->user->email, $request->email);
    }

    #[Test]
    public function forgedPublicUserListCannotAuthorizeANewEmailOnProduction(): void
    {
        $this->app['env'] = 'production';
        $component = $this->positionComponent('new@example.com');
        $component->partyUsers = collect([new User(['email' => 'new@example.com'])]);

        $this->expectException(AuthorizationException::class);
        (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);
    }

    #[Test]
    public function localLoginCanReuseTheSamePartysEmailOutsideTheCurrentFacility(): void
    {
        $this->app['env'] = 'local';
        $otherUser = User::create([
            'email' => 'same-party@example.com',
            'password' => 'unused',
            'party_id' => $this->party->id,
        ]);
        $component = $this->positionComponent($otherUser->email);
        $request = (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);

        $this->assertSame($otherUser->id, $request->userId);
    }

    #[Test]
    public function localEmailBelongingToAnotherPartyIsRejected(): void
    {
        $this->app['env'] = 'local';
        $otherParty = $this->party->replicate();
        $otherParty->uuid = (string) Str::uuid();
        $otherParty->save();
        User::create(['email' => 'other@example.com', 'password' => 'unused', 'party_id' => $otherParty->id]);
        $component = $this->positionComponent('other@example.com');

        $this->expectException(ValidationException::class);
        (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);
    }

    #[Test]
    public function localDraftCannotBeResavedThroughRequestEditOnProduction(): void
    {
        $this->app['env'] = 'local';
        $component = $this->positionComponent('new@example.com');
        $request = (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);
        $edit = new EmployeeRequestEdit();
        $edit->form = $component->form;
        $edit->employeeRequestId = $request->id;
        (new ReflectionProperty($edit, 'employeeRequest'))->setValue($edit, $request);
        $this->app['env'] = 'production';

        try {
            (new ReflectionMethod($edit, 'handleDraftPersistence'))->invoke($edit);
            $this->fail('The local-only draft was accepted on production.');
        } catch (AuthorizationException) {
            $this->assertSame('new@example.com', $request->fresh()->email);
            $this->assertDatabaseCount('employee_requests', 1);
            Http::assertNothingSent();
        }
    }

    public static function writeActions(): array
    {
        return [['save'], ['prepareForSigning'], ['sign']];
    }

    #[Test]
    #[DataProvider('writeActions')]
    public function publicActionsCannotWriteOrSignALocalDraftOnProduction(string $action): void
    {
        $this->app['env'] = 'local';
        $component = $this->positionComponent('new@example.com');
        $request = (new ReflectionMethod($component, 'handleDraftPersistence'))->invoke($component);
        $edit = new EmployeeRequestEdit();
        $edit->form = new LocalLoginActionForm($edit, 'form');
        $edit->form->fill($component->form->all());
        $edit->form->position = 'P2';
        $edit->employeeRequestId = $request->id;
        (new ReflectionProperty($edit, 'employeeRequest'))->setValue($edit, $request);
        $this->app['env'] = 'production';

        $edit->{$action}();

        $this->assertSame('P1', $request->fresh()->position);
        $this->assertNull($request->fresh()->uuid);
        $this->assertSame('This action is unauthorized.', session('error'));
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        Http::assertNothingSent();
    }

    private function positionComponent(string $email): EmployeePositionAdd
    {
        $component = new EmployeePositionAdd();
        $component->form = new EmployeeForm($component, 'form');
        $component->form->employeeType = 'HR';
        $component->form->position = 'P1';
        $component->form->startDate = '2026-01-01';
        $component->form->existingPartyId = $this->party->id;
        $component->form->party['email'] = $email;
        $component->formEmail = $email;
        $component->partyId = $this->party->id;
        $component->boot();

        return $component;
    }
}

// Isolate action authorization from unrelated personal/document validation.
class LocalLoginActionForm extends EmployeeForm
{
    public function rulesForSave(Component $component): array
    {
        return ['position' => ['required', 'string']];
    }
}

class LocalLoginPositionView extends EmployeePositionAdd
{
    public function mount(LegalEntity $legalEntity, Party $party): void
    {
        $this->authorize('create', [EmployeeRequest::class, $party]);
        $this->partyUsers = $party->usersWithEmployeeInLegalEntity($legalEntity->id);
        $this->dictionaries = ['EMPLOYEE_TYPE' => ['HR' => 'HR']];
        $this->form->employeeType = 'HR';
    }

    public function render(): View
    {
        return view('livewire.employee.parts.position');
    }
}
