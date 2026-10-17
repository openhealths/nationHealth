<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Dto\Division\Form;
use App\Enums\Status;
use App\Livewire\Division\DivisionComponent;
use App\Livewire\Division\DivisionEdit;
use App\Livewire\Division\DivisionView;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Address;
use App\Models\Relations\Phone;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tests\TestCase;

class DivisionFormMappingTest extends TestCase
{
    public function test_legal_entity_scope_accepts_only_the_legal_entity_id(): void
    {
        $query = Division::filterByLegalEntityId(42);

        $this->assertSame([42], $query->getBindings());
        $this->assertStringContainsString('legal_entity_id', $query->toSql());
    }

    public function test_base_component_declares_address_state_and_can_load_division(): void
    {
        $component = $this->divisionComponent(DivisionComponent::class);

        $this->assertTrue(property_exists(DivisionComponent::class, 'address'));
        $this->assertTrue(property_exists(DivisionComponent::class, 'receptionAddress'));

        $this->loadDivision($component, $this->division());

        $this->assertSame('Kyiv', $component->address['settlement']);
        $this->assertSame('Lviv', $component->receptionAddress['settlement']);
    }

    public function test_reception_detection_ignores_addresses_without_a_type(): void
    {
        $data = new Form();
        $data->addresses = [['country' => 'UA'], ['type' => 'RESIDENCE']];

        $this->assertFalse($data->hasReceptionAddress());

        $data->addresses[] = ['type' => 'reception'];

        $this->assertTrue($data->hasReceptionAddress());
    }

    private function division(): Division
    {
        $division = new Division([
            'uuid' => 'division-uuid', 'name' => 'Main division', 'type' => 'CLINIC',
            'email' => 'division@example.com', 'status' => 'ACTIVE', 'external_id' => '123',
            'location' => ['latitude' => 0, 'longitude' => 0],
            'working_hours' => ['mon' => [['08:00', '17:30']]],
        ]);
        $division->id = 42;
        $division->setRelation('addresses', new Collection([
            new Address([
                'type' => 'RESIDENCE', 'country' => 'UA', 'settlement' => 'Kyiv',
                'settlement_id' => 'settlement-uuid', 'settlement_type' => 'CITY',
                'street_type' => 'STREET', 'addressable_id' => 42,
            ]),
            new Address(['type' => 'RECEPTION', 'country' => 'UA', 'settlement' => 'Lviv']),
        ]));
        $division->setRelation('phones', new Collection([
            new Phone(['type' => 'MOBILE', 'number' => '+380501234567', 'phoneable_id' => 42]),
        ]));
        $division->setRelation('employees', new Collection());

        return $division;
    }

    /** @return array<string, array{class-string<DivisionComponent>}> */
    public static function components(): array
    {
        return ['edit' => [DivisionEdit::class], 'view' => [DivisionView::class]];
    }

    /** @param class-string<DivisionComponent> $componentClass */
    private function divisionComponent(string $componentClass): DivisionComponent
    {
        $component = new $componentClass();
        $component->divisionForm = new DivisionForm($component, 'divisionForm');

        return $component;
    }

    private function loadDivision(DivisionComponent $component, Division $division): void
    {
        (new ReflectionMethod(DivisionComponent::class, 'setDivisionData'))->invoke($component, $division);
    }

    public static function divisionStatuses(): array
    {
        return [
            'active' => [Status::ACTIVE, 'forms.status.active', 'status-alert-green'],
            'inactive' => [Status::INACTIVE, 'forms.status.non_active', 'status-alert-red'],
            'draft' => [Status::DRAFT, 'forms.status.draft', 'status-alert-red'],
            'unsynced' => [Status::UNSYNCED, 'forms.status.unsynced', 'status-alert-yellow'],
        ];
    }

    #[DataProvider('divisionStatuses')]
    public function test_view_mount_displays_ehealth_and_local_statuses(Status $status, string $label, string $style): void
    {
        $division = $this->division();
        $division->status = $status;
        $division->uuid = $status === Status::DRAFT ? null : $division->uuid;
        $component = Mockery::mock(DivisionView::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->shouldReceive('setDictionary')->once()->andReturnSelf();
        $component->divisionForm = new DivisionForm($component, 'divisionForm');

        $component->mount(new LegalEntity(), $division);

        $this->assertSame(__($label), $component->statusLabel);
        $this->assertSame($style, $component->statusStyle);
        $this->assertSame($status->value, $component->divisionForm->division['status']);
        $this->assertSame($division->uuid ?? '', $component->divisionUuid);
    }

    public function test_maps_model_directly_to_form_dto_without_unrelated_relations(): void
    {
        $division = $this->division();
        $before = $division->toArray();

        $data = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
            ->map($division, Form::class);

        $this->assertSame(array_replace($division->attributesToArray(), [
            'addresses' => $division->addresses->toArray(),
            'phones' => $division->phones->toArray(),
        ]), $data->toArray());
        $this->assertArrayNotHasKey('employees', $data->toArray());
        $this->assertSame($before, $division->toArray());
    }

    #[DataProvider('components')]
    public function test_empty_weekday_is_loaded_as_a_day_off(string $componentClass): void
    {
        $division = $this->division();
        $division->workingHours = [
            'mon' => [['08.00', '12.00'], ['13.00', '17.00']],
            'sat' => [['00.00', '00.00']],
            'sun' => [],
        ];
        $component = $this->divisionComponent($componentClass);

        $this->loadDivision($component, $division);

        $hours = $component->divisionForm->division['workingHours'];
        $this->assertSame([['-1', '-1']], $hours['sun']);
        $this->assertSame([['-1', '-1']], $hours['sat']);
        $this->assertSame([['-1', '-1']], $hours['tue']);
        $this->assertSame([['08:00', '12:00'], ['13:00', '17:00']], $hours['mon']);
        $this->assertFalse($component->divisionForm->workingHoursShifts['sun']);
        $this->assertTrue($component->divisionForm->workingHoursShifts['mon']);
    }

    #[DataProvider('components')]
    public function test_all_empty_weekdays_are_loaded_as_days_off(string $componentClass): void
    {
        $division = $this->division();
        $division->workingHours = array_fill_keys(array_keys(Division::getWorkingDaysTemplate()), []);
        $component = $this->divisionComponent($componentClass);

        $this->loadDivision($component, $division);

        $this->assertSame(Division::getWorkingDaysTemplate(), $component->divisionForm->division['workingHours']);
        $this->assertNotContains(true, $component->divisionForm->workingHoursShifts);
    }

    #[DataProvider('components')]
    public function test_populates_edit_and_view_form_and_address_state(string $componentClass): void
    {
        $division = $this->division();
        $component = $this->divisionComponent($componentClass);

        $this->loadDivision($component, $division);

        $data = $component->divisionForm->division;
        $this->assertSame(42, $data['id']);
        $this->assertSame('division-uuid', $data['uuid']);
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertSame('123', $data['externalId']);
        $this->assertSame($division->location, $data['location']);
        $this->assertSame($division->workingHours, $data['workingHours']);
        $this->assertSame('settlement-uuid', $component->address['settlementId']);
        $this->assertSame('CITY', $component->address['settlementType']);
        $this->assertSame('STREET', $component->address['streetType']);
        $this->assertSame('Lviv', $component->receptionAddress['settlement']);
        $this->assertTrue($component->divisionForm->showReceptionAddress);
        $this->assertSame('+380501234567', $data['phones'][0]['number']);
        $this->assertArrayNotHasKey('phoneableId', $data['phones'][0]);
        $this->assertArrayNotHasKey('addressable_id', $component->address);
    }

    #[DataProvider('components')]
    public function test_reloading_without_addresses_resets_stale_component_state(string $componentClass): void
    {
        $component = $this->divisionComponent($componentClass);
        $division = $this->division();
        $this->loadDivision($component, $division);
        $division->setRelation('addresses', new Collection());
        $division->setRelation('phones', new Collection());

        $this->loadDivision($component, $division);

        $this->assertSame(['country' => 'UA', 'type' => 'RESIDENCE'], $component->address);
        $this->assertSame(['country' => 'UA', 'type' => 'RECEPTION'], $component->receptionAddress);
        $this->assertFalse($component->divisionForm->showReceptionAddress);
        $this->assertSame([], $component->divisionForm->division['addresses']);
        $this->assertSame([], $component->divisionForm->division['phones']);
    }

    public function test_missing_identifiers_and_cast_defaults_are_available_in_form(): void
    {
        $division = new Division();
        $division->setRelation('addresses', new Collection());
        $division->setRelation('phones', new Collection());
        $component = $this->divisionComponent(DivisionEdit::class);

        $this->loadDivision($component, $division);

        $this->assertSame('', $component->divisionForm->division['id']);
        $this->assertSame('', $component->divisionForm->division['uuid']);
        $this->assertSame($division->attributesToArray()['workingHours'] ?? null, $component->divisionForm->division['workingHours'] ?? null);
    }

    public function test_address_selection_ignores_unknown_types_and_accepts_lowercase(): void
    {
        $division = $this->division();
        $division->addresses[0]->type = 'residence';
        $division->addresses[1]->type = 'reception';
        $division->addresses->push(new Address(['type' => 'OTHER', 'settlement' => 'Unknown']));
        $component = $this->divisionComponent(DivisionView::class);

        $this->loadDivision($component, $division);

        $this->assertSame('Kyiv', $component->address['settlement']);
        $this->assertSame('Lviv', $component->receptionAddress['settlement']);
        $this->assertTrue($component->divisionForm->showReceptionAddress);
    }
}
