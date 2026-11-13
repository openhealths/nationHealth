<?php

declare(strict_types=1);

namespace Tests\Feature\Declaration;

use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Events\EHealthUserLogin;
use App\Jobs\CompleteSync;
use App\Jobs\ConnectionSync;
use App\Jobs\DeclarationsSync;
use App\Jobs\EmployeeRoleSync;
use App\Jobs\EquipmentSync;
use App\Jobs\LegalEntityDetailsSync;
use App\Jobs\LegalEntitySync;
use App\Listeners\FirstLoginOwnerSynchronization;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\User;
use App\Notifications\SyncNotification;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class FirstLoginDeclarationScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array', 'permission.cache.store' => 'array', 'session.driver' => 'array']);
        Bus::fake();
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'first-login-test-token']);
    }

    #[DataProvider('tokenScopes')]
    public function test_declaration_chain_requires_all_token_scopes(array $scopes, string $status, bool $allowed): void
    {
        $this->assertDeclarationChain($scopes, $status, LegalEntity::TYPE_PRIMARY_CARE, $allowed);
    }

    public static function tokenScopes(): array
    {
        $requiredScopes = ['declaration:read', 'declaration_request:read', 'person:read'];
        $cases = [];

        foreach (['ACTIVE', 'NEW'] as $status) {
            for ($mask = 0; $mask < 8; $mask++) {
                $scopes = array_values(array_filter($requiredScopes, fn (string $scope, int $index): bool => ($mask & (1 << $index)) !== 0, ARRAY_FILTER_USE_BOTH));
                $cases[$status . ' / ' . ($scopes === [] ? 'no scopes' : implode(' ', $scopes))] = [$scopes, $status, $mask === 7];
            }
        }

        return $cases;
    }

    #[DataProvider('roleGrants')]
    public function test_chain_uses_granted_scopes_instead_of_assigned_roles(array $roles, bool $allowed, string $type = LegalEntity::TYPE_PRIMARY_CARE): void
    {
        $scopes = collect($roles)->flatMap(fn (string $role): array => config('ehealth.roles.' . $role))
            ->intersect(config('ehealth.legal_entity_types.' . $type))->unique()->values()->all();

        $this->assertDeclarationChain($scopes, 'ACTIVE', $type, $allowed);
    }

    public static function roleGrants(): array
    {
        return [
            'OWNER only' => [['OWNER'], false],
            'REORGANIZATION_OWNER only' => [['REORGANIZATION_OWNER'], false],
            'DOCTOR only' => [['DOCTOR'], true],
            'OWNER and DOCTOR' => [['OWNER', 'DOCTOR'], true],
            'OWNER and RECEPTIONIST' => [['OWNER', 'RECEPTIONIST'], true],
            'OUTPATIENT OWNER only' => [['OWNER'], false, LegalEntity::TYPE_OUTPATIENT],
            'OUTPATIENT OWNER and DOCTOR' => [['OWNER', 'DOCTOR'], false, LegalEntity::TYPE_OUTPATIENT],
            'OUTPATIENT OWNER and RECEPTIONIST' => [['OWNER', 'RECEPTIONIST'], false, LegalEntity::TYPE_OUTPATIENT],
            'ADMIN only' => [['ADMIN'], false],
            'HR only' => [['HR'], false],
            'RECEPTIONIST only' => [['RECEPTIONIST'], true],
            'ASSISTANT only' => [['ASSISTANT'], false],
        ];
    }

    #[DataProvider('excludedLegalEntityTypes')]
    public function test_excluded_legal_entity_types_skip_declarations(string $type, string $status): void
    {
        $this->assertDeclarationChain(['declaration:read', 'declaration_request:read', 'person:read'], $status, $type, false);
    }

    public static function excludedLegalEntityTypes(): array
    {
        $cases = [];
        foreach ([LegalEntity::TYPE_PHARMACY, LegalEntity::TYPE_EMERGENCY, LegalEntity::TYPE_OUTPATIENT] as $type) {
            foreach (['ACTIVE', 'NEW'] as $status) {
                $cases[$type . ' / ' . $status] = [$type, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('supportedLegalEntityTypes')]
    public function test_supported_legal_entity_types_include_declarations_with_all_required_scopes(string $type): void
    {
        $this->assertDeclarationChain(['declaration:read', 'declaration_request:read', 'person:read'], 'ACTIVE', $type, true);
    }

    public static function supportedLegalEntityTypes(): array
    {
        return [['MSP'], ['PRIMARY_CARE'], ['MSP_PHARMACY'], ['MSP_LIMITED']];
    }

    protected function assertDeclarationChain(array $scopes, string $status, string $type, bool $allowed): void
    {
        $legalEntity = Mockery::mock(LegalEntity::class)->makePartial();
        $legalEntity->setRawAttributes(['id' => 1, 'status' => $status, 'sync_status' => null]);
        $legalEntity->setRelation('type', new LegalEntityType(['name' => $type]));
        $legalEntity->shouldReceive('setEntityStatus')->once()->with(JobStatus::PROCESSING)->andReturnNull();
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('load')->once()->with('roles', 'permissions', 'party')->andReturnSelf();
        $user->shouldReceive('notify')->once()->with(Mockery::type(SyncNotification::class));
        $user->shouldReceive('checkPermissionTo')->with('sync', null)->andReturnFalse();
        $user->shouldNotReceive('hasPermissionTo');
        $user->shouldNotReceive('hasAllowedRole');
        $event = new EHealthUserLogin($user, $legalEntity, 'test-user', $scopes, true);
        $this->expectOutputRegex('/First login synchronization started/');

        new FirstLoginOwnerSynchronization()->handle($event);

        Bus::assertBatchCount(1);
        $batch = Bus::batched(fn ($batch): bool => $batch->name === 'FirstLoginSync')->sole();
        $job = $batch->jobs[0];
        if ($status === 'NEW') {
            $this->assertInstanceOf(LegalEntityDetailsSync::class, $job);
            // The queue supplies the user and the details response activates the entity before building the chain.
            $job->user = $user;
            $legalEntity->status = 'ACTIVE';
            $job = new ReflectionMethod($job, 'getNextEntityJob')->invoke($job);
        }

        $classes = [];
        $nextEntity = new ReflectionProperty(EHealthJob::class, 'nextEntity');
        while ($job !== null) {
            $classes[] = $job::class;
            $job = $nextEntity->getValue($job);
        }

        $this->assertSame($allowed, in_array(DeclarationsSync::class, $classes, true));
        $this->assertSame(in_array('equipment:read', $scopes, true), in_array(EquipmentSync::class, $classes, true));
        $this->assertContains(EmployeeRoleSync::class, $classes);
        $this->assertContains(LegalEntitySync::class, $classes);
        $this->assertContains(ConnectionSync::class, $classes);
        $this->assertSame(CompleteSync::class, $classes[array_key_last($classes)]);
    }
}
