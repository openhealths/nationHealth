<?php

declare(strict_types=1);

namespace Tests\Feature\LegalEntity;

use App\Livewire\LegalEntity\EditLegalEntity;
use App\Livewire\LegalEntity\Forms\LegalEntitiesForms;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;
use App\Livewire\LegalEntity\LegalEntityDetails;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class LegalEntityOwnerLoadingTest extends TestCase
{
    private LegalEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'app.date_format' => 'd.m.Y',
        ]);
        Http::preventStrayRequests();

        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000022_create_divisions_table.php',
            '2025_12_10_000024_create_parties_table.php',
            '2025_12_10_000028_create_licenses_table.php',
            '2025_12_10_000030_create_documents_table.php',
            '2025_12_10_000032_create_phones_table.php',
            '2025_12_10_000042_create_addresses_table.php',
            '2025_12_10_000070_create_revisions_table.php',
            '2025_12_10_000088_create_users_table.php',
            '2025_12_10_000090_create_employees_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }

        $this->actingAs(new User(['email' => 'viewer@example.test']), 'ehealth');
        $this->entity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->value('id'),
            'edrpou' => '12345678',
            'email' => 'facility@example.test',
            'status' => 'ACTIVE',
        ]);
        $this->app->instance('legalEntity', $this->entity);
        $this->entity->licenses()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'MSP',
            'issued_by' => 'МОЗ',
            'issued_date' => '2025-01-01',
            'active_from_date' => '2025-01-01',
            'order_no' => '123',
            'is_primary' => true,
        ]);
    }

    #[DataProvider('missingOwners')]
    public function test_form_loads_when_there_is_no_approved_local_owner(string $componentClass, ?string $employeeType, ?string $status): void
    {
        if ($employeeType !== null) {
            $this->employee($employeeType, $status);
        }
        $this->assertNull($this->entity->getOwner());

        $component = $this->loadForm($componentClass);

        $this->assertSame([], $component->legalEntityForm->owner);
        $this->assertSame('12345678', $component->legalEntityForm->edrpou);
        $this->assertSame('facility@example.test', $component->legalEntityForm->email);
        $this->assertSame('MSP', $component->legalEntityForm->license['type']);
    }

    public static function missingOwners(): array
    {
        $cases = [];
        foreach ([LegalEntityDetails::class, EditLegalEntity::class] as $component) {
            $cases[$component . ' no employees'] = [$component, null, null];
            $cases[$component . ' stopped owner'] = [$component, 'OWNER', 'STOPPED'];
            $cases[$component . ' another employee type'] = [$component, 'DOCTOR', 'APPROVED'];
        }

        return $cases;
    }

    #[DataProvider('components')]
    public function test_form_still_loads_the_existing_approved_owner(string $componentClass): void
    {
        $owner = $this->employee('OWNER', 'APPROVED');
        $owner->party->phones()->create(['type' => 'MOBILE', 'number' => '+380501234567']);
        $owner->party->documents()->create(['type' => 'NATIONAL_ID', 'number' => '123456789']);

        $component = $this->loadForm($componentClass);
        $data = $component->legalEntityForm->owner;

        $this->assertSame('Керівник', $data['lastName']);
        $this->assertSame('Іван', $data['firstName']);
        $this->assertSame('01.01.1980', $data['birthDate']);
        $this->assertSame('DIRECTOR', $data['position']);
        $this->assertSame('+380501234567', $data['phones'][0]['number']);
        $this->assertSame('123456789', $data['documents'][0]['number']);
        if ($component instanceof EditLegalEntity) {
            $this->assertSame($owner->uuid, $data['employee_uuid']);
            $this->assertSame($owner->id, $data['employee_id']);
        } else {
            $this->assertSame($owner->uuid, $data['employee_id']);
        }
    }

    public static function components(): array
    {
        return [
            'details' => [LegalEntityDetails::class],
            'edit' => [EditLegalEntity::class],
        ];
    }

    private function loadForm(string $componentClass): LegalEntityComponent
    {
        $component = new $componentClass();
        $component->legalEntityForm = new LegalEntitiesForms($component, 'legalEntityForm');
        $component->boot(Repository::address(), Repository::phone());
        new ReflectionMethod($componentClass, 'getLegalEntityForm')->invoke($component);

        return $component;
    }

    private function employee(string $type, string $status): Employee
    {
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => 'Керівник',
            'first_name' => 'Іван',
            'birth_date' => '1980-01-01',
        ]);

        return $this->entity->employees()->create([
            'uuid' => (string) Str::uuid(),
            'party_id' => $party->id,
            'employee_type' => $type,
            'status' => $status,
            'position' => 'DIRECTOR',
            'start_date' => '2025-01-01',
        ]);
    }
}
