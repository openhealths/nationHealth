<?php

declare(strict_types=1);

namespace Tests\Feature\License;

use App\Classes\eHealth\Api\License as LicenseApi;
use App\Livewire\License\Forms\LicenseForm;
use App\Livewire\License\LicenseCreate;
use App\Livewire\License\LicenseEdit;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class OutpatientAdditionalLicenseActivityTest extends TestCase
{
    use MocksBasicDictionaries;

    private LegalEntity $entity;

    private License $primary;

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
        Http::preventStrayRequests();

        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000028_create_licenses_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }

        $this->entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => LegalEntityType::whereName(LegalEntity::TYPE_OUTPATIENT)->value('id'),
            'status' => 'ACTIVE',
        ]);
        $this->app->instance('legalEntity', $this->entity);
        $this->primary = $this->entity->licenses()->create(array_replace($this->licenseData(), [
            'type' => 'MSP',
            'is_primary' => true,
            'what_licensed' => null,
        ]));
        $this->mockBasicDictionaries([
            'LICENSE_TYPE' => ['MSP' => 'Медична практика', 'PHARMACY_DRUGS' => 'Обіг лікарських засобів'],
        ]);

        // Keep the real Gate and LicensePolicy; supply only the user's permissions.
        $user = new class extends User
        {
            public function checkPermissionTo($permission, $guardName = null): bool
            {
                return in_array($permission, ['license:write', 'license:read', 'license:details'], true);
            }
        };
        $user->id = 123;
        $this->actingAs($user, 'ehealth');
    }

    #[DataProvider('activities')]
    public function test_additional_license_action_requires_activity_for_outpatient(
        string $operation,
        ?string $value,
        bool $valid
    ): void {
        $license = $operation === 'update'
            ? $this->entity->licenses()->create($this->licenseData())
            : null;
        $component = $operation === 'create' ? new LicenseCreate() : new LicenseEdit();
        $component->form = new LicenseForm($component, 'form');
        $component->boot();
        $this->assertSame(['PHARMACY_DRUGS'], array_keys($component->licenseTypes));
        if ($license) {
            $component->mount($this->entity, $license);
        } else {
            $component->mount($this->entity);
        }
        $component->form->fill([
            'type' => 'PHARMACY_DRUGS',
            'issuedBy' => 'МОЗ',
            'issuedDate' => '01.01.2025',
            'activeFromDate' => '01.01.2025',
            'expiryDate' => now()->addYear()->format('d.m.Y'),
            'orderNo' => '123',
            'isPrimary' => false,
        ]);
        if ($value === null) {
            unset($component->form->whatLicensed);
        } else {
            $component->form->whatLicensed = $value;
        }

        $response = $this->licenseData();
        $response['id'] = $license?->uuid ?? $response['uuid'];
        $response['legal_entity_id'] = $this->entity->uuid;
        $response['what_licensed'] = $value;
        unset($response['uuid']);
        foreach (['inserted', 'updated'] as $action) {
            $response[$action . '_at'] = '2025-01-01T00:00:00Z';
            $response[$action . '_by'] = (string) Str::uuid();
        }
        Http::fake(['*' => Http::response(['data' => $response])]);
        $factory = Http::getFacadeRoot();
        $api = new LicenseApi($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $this->instance(LicenseApi::class, $api);

        $component->{$operation}();

        if ($valid) {
            $this->assertFalse($component->getErrorBag()->any());
            $this->assertSame(__('licenses.success.' . ($operation === 'create' ? 'created' : 'updated')), session('success'));
            Http::assertSent(static fn (Request $request): bool =>
                $request->method() === ($operation === 'create' ? 'POST' : 'PATCH')
                && ($request->data()['what_licensed'] ?? null) === $value);
            $this->assertSame($value, License::whereUuid($response['id'])->sole()->what_licensed);
        } else {
            $this->assertTrue($component->getErrorBag()->has('form.whatLicensed'));
            $this->assertFalse($component->getErrorBag()->has('form.type'));
            $this->assertNull(session('success'));
            Http::assertNothingSent();
            $this->assertSame($operation === 'create' ? 1 : 2, License::count());
            if ($license) {
                $this->assertSame('Попередня діяльність', $license->fresh()->what_licensed);
            }
        }
        $this->assertNull($this->primary->fresh()->what_licensed);
    }

    public static function activities(): array
    {
        $cases = [];
        foreach (['create', 'update'] as $operation) {
            foreach ([
                'missing' => [null, false],
                'empty' => ['', false],
                'whitespace' => [" \t\n", false],
                'overlong' => [str_repeat('А', 256), false],
                'provided' => ['Обіг лікарських засобів', true],
                'zero' => ['0', true],
                'maximum length' => [str_repeat('А', 255), true],
            ] as $name => [$value, $valid]) {
                $cases[$operation . ' ' . $name] = [$operation, $value, $valid];
            }
        }

        return $cases;
    }

    private function licenseData(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => 'PHARMACY_DRUGS',
            'is_primary' => false,
            'is_active' => true,
            'issued_by' => 'МОЗ',
            'issued_date' => '2025-01-01',
            'active_from_date' => '2025-01-01',
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'order_no' => '123',
            'what_licensed' => 'Попередня діяльність',
        ];
    }
}
