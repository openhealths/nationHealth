<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Classes\eHealth\Api\DeclarationRequest as DeclarationRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Declaration\RequestStatus;
use App\Enums\User\Role;
use App\Livewire\Declaration\DeclarationEdit;
use App\Livewire\Declaration\Forms\DeclarationForm;
use App\Models\Declaration;
use App\Models\DeclarationRequest;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\User;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as UploadResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeclarationApprovalScopesTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('approvalRoles')]
    public function test_approval_uses_current_role_and_legal_entity_scopes(string $type, Role $role, bool $allowed): void
    {
        [$user, $legalEntity, $employee] = $this->loginWithRole($type, $role);
        $request = $this->newRequest($legalEntity, $employee);

        $this->assertSame($allowed, $user->can('approve', DeclarationRequest::class));
        $this->assertSame($allowed, $user->can('approve', $request));

        if (in_array($role, [Role::DOCTOR, Role::RECEPTIONIST], true)) {
            $this->assertFalse($user->can('declaration_request:approve'));
        }
    }

    public static function approvalRoles(): array
    {
        $cases = [];
        foreach ([
            LegalEntity::TYPE_MSP => [Role::OWNER, Role::HR, Role::DOCTOR, Role::ADMIN, Role::RECEPTIONIST, Role::LABORANT],
            LegalEntity::TYPE_PRIMARY_CARE => [Role::REORGANIZATION_OWNER, Role::OWNER, Role::HR, Role::DOCTOR, Role::ASSISTANT, Role::ADMIN, Role::RECEPTIONIST, Role::MED_ADMIN, Role::LABORANT],
        ] as $type => $roles) {
            foreach ($roles as $role) {
                $cases[$type . ' / ' . $role->value] = [
                    $type,
                    $role,
                    in_array($role, [Role::DOCTOR, Role::ASSISTANT, Role::RECEPTIONIST], true),
                ];
            }
        }

        return $cases;
    }

    public function test_legacy_approve_scope_does_not_replace_write_scope(): void
    {
        [$user, $legalEntity, $employee] = $this->loginWithRole();
        $legacyPermission = Permission::findByName('declaration_request:approve', 'ehealth');
        $user->permissions()->sync([$legacyPermission->id => ['legal_entity_id' => $legalEntity->id]]);
        $user = $user->fresh();
        $this->actingAs($user, 'ehealth');

        $this->assertTrue($user->can('declaration_request:approve'));
        $this->assertFalse($user->can('declaration_request:write'));
        $this->assertFalse($user->can('approve', $this->newRequest($legalEntity, $employee)));
    }

    #[DataProvider('requestStatuses')]
    public function test_only_new_requests_of_the_current_legal_entity_can_be_approved(RequestStatus $status): void
    {
        [$user, $legalEntity, $employee] = $this->loginWithRole();
        $request = $this->newRequest($legalEntity, $employee);
        $request->status = $status;

        $this->assertSame($status === RequestStatus::NEW, $user->can('approve', $request));

        $request->status = RequestStatus::NEW;
        $request->legalEntityId = $legalEntity->id + 1;
        $this->assertFalse($user->can('approve', $request));
    }

    public static function requestStatuses(): array
    {
        return array_map(fn (RequestStatus $status): array => [$status], RequestStatus::cases());
    }

    public function test_simplified_request_requires_its_own_doctor(): void
    {
        [$user, $legalEntity, $employee] = $this->loginWithRole();
        $request = $this->newRequest($legalEntity, $employee, true);

        $this->assertTrue($user->can('approve', $request));

        $employee->partyId = Party::factory()->create()->id;
        $employee->save();
        $request->unsetRelation('employee');

        $this->assertFalse($user->can('approve', $request));
    }

    #[DataProvider('resigningScopes')]
    public function test_doctor_can_resign_with_write_and_sign_scopes(array $missingScopes, bool $allowed): void
    {
        [$user] = $this->loginWithRole();
        foreach ($missingScopes as $scope) {
            $user->revokePermissionTo($scope);
        }
        $user = $user->fresh();
        $this->actingAs($user, 'ehealth');

        $this->assertFalse($user->can('declaration_request:approve'));
        $this->assertSame($allowed, $user->can('resign', Declaration::class));
    }

    public static function resigningScopes(): array
    {
        return [
            'current doctor scopes without legacy approve' => [[], true],
            'missing write' => [['declaration_request:write'], false],
            'missing sign' => [['declaration_request:sign'], false],
        ];
    }

    #[DataProvider('ineligibleEmployees')]
    public function test_resigning_still_requires_an_active_doctor_with_a_division(array $attributes): void
    {
        [$user, , $employee] = $this->loginWithRole();
        $employee->update($attributes);

        $this->assertFalse($user->can('resign', Declaration::class));
    }

    public static function ineligibleEmployees(): array
    {
        return [
            'inactive doctor' => [['is_active' => false]],
            'doctor without division' => [['division_id' => null]],
        ];
    }

    #[DataProvider('approvalMethods')]
    public function test_doctor_approval_reaches_v3_and_opens_signing_without_legacy_scope(string $method): void
    {
        [$user, $legalEntity, $employee] = $this->loginWithRole();
        $simplified = $method === 'simplified';
        $request = $this->newRequest($legalEntity, $employee, $simplified);
        $request->save();
        $dataToSign = ['seed' => 'approved-seed', 'content' => 'Declaration printout'];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'id' => $request->uuid,
            'status' => RequestStatus::APPROVED->value,
            'data_to_be_signed' => $dataToSign,
        ]);
        $api = Mockery::mock(DeclarationRequestApi::class);
        $api->shouldReceive('approve')->once()->withArgs(fn (...$arguments): bool => $arguments === (
            $method === 'sms' ? [$request->uuid, ['verification_code' => 1234]] : [$request->uuid]
        ))->andReturn($response);
        $this->instance(DeclarationRequestApi::class, $api);

        $component = Mockery::mock(DeclarationEdit::class)->makePartial()->shouldAllowMockingProtectedMethods();
        if ($simplified) {
            $component->shouldNotReceive('syncDeclarationRelatedData');
        } else {
            $component->shouldReceive('syncDeclarationRelatedData')->once()->with($dataToSign)->andReturnNull();
        }
        $component->form = new DeclarationForm($component, 'form');
        $component->form->verificationCode = 1234;
        $component->form->processDisclosureDataConsent = true;
        $component->declarationRequestId = $request->id;
        $component->declarationRequestUuid = $request->uuid;
        $component->personId = $request->personId;
        $component->isNeedToResign = $simplified;
        $component->status = RequestStatus::NEW;

        if ($method === 'offline') {
            $document = UploadedFile::fake()->image('passport.jpg');
            $component->form->uploadedDocuments = [$document];
            $component->uploadedDocuments = [['url' => 'https://upload.example.test/document']];
            $api->shouldReceive('uploadDocument')->once()->with('https://upload.example.test/document', $document)
                ->andReturn(new UploadResponse(new PsrResponse(200)));
            $component->sendFiles();
        } elseif ($simplified) {
            $component->approveSimplifiedDeclaration();
        } else {
            $component->approve();
        }

        $this->assertFalse($user->can('declaration_request:approve'));
        $this->assertNull(session('error'));
        $this->assertSame(RequestStatus::APPROVED, $request->fresh()->status);
        $this->assertSame(RequestStatus::APPROVED, $component->status);
        $this->assertSame($dataToSign, $component->dataToBeSigned);
        $this->assertTrue($component->showSignModal);
        $this->assertTrue($user->can('sign', $request->fresh()));
    }

    public static function approvalMethods(): array
    {
        return [['sms'], ['offline'], ['simplified']];
    }

    protected function loginWithRole(string $type = LegalEntity::TYPE_PRIMARY_CARE, Role $role = Role::DOCTOR): array
    {
        Auth::shouldUse('ehealth');
        $legalEntity = LegalEntity::factory()->ofType($type)->create();
        $this->instance('legalEntity', $legalEntity);
        setPermissionsTeamId($legalEntity->id);
        $party = Party::factory()->create();
        $user = User::factory()->create(['party_id' => $party->id]);
        $user->assignRole($role);
        $user->syncPermissions(array_intersect(config('ehealth.roles.' . $role->value), config('ehealth.legal_entity_types.' . $type)));
        $division = Division::factory()->create(['legal_entity_id' => $legalEntity->id]);
        $employee = Employee::factory()->create([
            'party_id' => $party->id,
            'division_id' => $division->id,
            'legal_entity_id' => $legalEntity->id,
            'employee_type' => $role->value,
        ]);
        $user = $user->fresh();
        $this->actingAs($user, 'ehealth');
        $this->withSession(['single_role_login' => $role->value]);

        return [$user, $legalEntity, $employee];
    }

    protected function newRequest(LegalEntity $legalEntity, Employee $employee, bool $simplified = false): DeclarationRequest
    {
        return new DeclarationRequest([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'employee_id' => $employee->id,
            'division_id' => $employee->divisionId,
            'status' => RequestStatus::NEW,
            'parent_declaration_uuid' => $simplified ? (string) Str::uuid() : null,
        ]);
    }
}
