<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Division\Model;
use App\Dto\Division\DivisionGetMany;
use App\Livewire\Division\DivisionComponent;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

trait DivisionMappingFixtures
{
    protected function mapList(array $rows): DivisionGetMany
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidPropertyPath()->getPropertyAccessor())
            ->map(new Collection(['divisions' => $rows]), DivisionGetMany::class);
    }

    protected function response(array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid(),
            'name' => 'Main division',
            'type' => 'CLINIC',
            'email' => 'division@example.com',
            'status' => 'ACTIVE',
            'external_id' => '123',
            'mountain_group' => false,
            'location' => ['latitude' => 0.0, 'longitude' => 0.0],
            'working_hours' => ['mon' => [['08.00', '17.30']], 'sun' => []],
            'dls_id' => 'license-id',
            'dls_verified' => false,
            'inserted_at' => '2026-10-04',
            'updated_at' => '2026-10-04',
            'inserted_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'addresses' => [[
                'type' => 'RESIDENCE', 'country' => 'UA', 'area' => 'Kyiv',
                'settlement' => 'Kyiv', 'settlement_id' => (string) Str::uuid(),
                'settlement_type' => 'CITY', 'street_type' => 'STREET', 'building' => '0',
            ]],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567', 'note' => 'Primary']],
        ], $overrides);
    }

    protected function mapResponse(array $data): Model
    {
        $api = new class extends DivisionApi
        {
            public function validateResponse(EHealthResponse $response): DivisionCreate
            {
                return $this->validateOne($response);
            }
        };

        $response = new EHealthResponse(
            new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)),
            $api->validateResponse(...)
        );
        $validated = $response->validate();

        $this->assertInstanceOf(DivisionCreate::class, $validated);

        return (new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidPropertyPath()
            ->getPropertyAccessor()))
            ->map($validated, Model::class);
    }

    protected function formComponent(array $overrides = []): DivisionComponent
    {
        $component = new class extends DivisionComponent
        {
            public function saveForm(): ?Division
            {
                return $this->saveToDB();
            }
        };
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = array_replace([
            'name' => 'Draft division', 'type' => 'CLINIC', 'email' => 'draft@example.com',
            'externalId' => 123, 'mountainGroup' => false,
            'location' => ['latitude' => 0, 'longitude' => 0],
            'workingHours' => ['mon' => [['08:00', '17:30']]],
            'addresses' => ['residence' => [
                'type' => 'RESIDENCE', 'country' => 'UA', 'area' => 'Kyiv',
                'settlement' => 'Kyiv', 'settlementId' => (string) Str::uuid(),
                'settlementType' => 'CITY', 'streetType' => 'STREET', 'building' => '0',
            ]],
            'phones' => [['type' => 'MOBILE', 'number' => '+380501234567']],
        ], $overrides);

        return $component;
    }

    protected function mapForm(DivisionForm $form): Model
    {
        return (new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidPropertyPath()->getPropertyAccessor()))
            ->map($form, Model::class);
    }
}
