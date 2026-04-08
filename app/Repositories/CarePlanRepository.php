<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CarePlan;
use Illuminate\Database\Eloquent\Collection;

class CarePlanRepository
{
    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::where('legal_entity_id', $legalEntityId)
            ->with(['person', 'author.party'])
            ->latest()
            ->get();
    }

    public function getByPersonId(int $personId): Collection
    {
        return CarePlan::where('person_id', $personId)
            ->with(['person', 'author.party'])
            ->latest()
            ->get();
    }
    
    public function findById(int $id): ?CarePlan
    {
        return CarePlan::with(['person', 'author.party', 'activities'])->find($id);
    }
    
    public function findByUuid(string $uuid): ?CarePlan
    {
        return CarePlan::with(['person', 'author.party', 'activities'])->where('uuid', $uuid)->first();
    }
    
    public function create(array $data): CarePlan
    {
        return CarePlan::create($data);
    }
    
    public function update(CarePlan $carePlan, array $data): bool
    {
        return $carePlan->update($data);
    }

    public function updateById(int $id, array $data): bool
    {
        $carePlan = CarePlan::find($id);
        if (!$carePlan) {
            return false;
        }
        return $carePlan->update($data);
    }

    /**
     * Format Care Plan data into the proper FHIR schema for eHealth API requests.
     */
    public function formatCarePlanRequest(array $form, ?string $encounterUuid, array $encounterData, ?string $employeeUuid): array
    {
        return \App\Core\Arr::removeEmptyKeys([
            'intent' => 'order',
            'status' => 'new',
            'category' => $form['category'],
            'instantiates_protocol' => !empty($form['clinical_protocol']) ? [['display' => $form['clinical_protocol']]] : null,
            'context' => !empty($form['context']) ? ['identifier' => ['type_code' => $form['context']]] : null,
            'title' => $form['title'],
            'period' => array_filter([
                'start' => convertToYmd($form['period_start']),
                'end' => !empty($form['period_end']) ? convertToYmd($form['period_end']) : null,
            ]),
            'addresses' => $encounterData['addresses'] ?? null,
            'supporting_info' => array_merge(
                array_map(fn($e) => ['display' => $e['name']], $form['episodes'] ?? []),
                array_map(fn($m) => ['display' => $m['name']], $form['medical_records'] ?? [])
            ),
            'encounter' => !empty($form['encounter']) ? ['identifier' => ['value' => $form['encounter']]] : null,
            'care_manager' => ['identifier' => ['value' => $employeeUuid]],
            'description' => $form['description'] ?: null,
            'note' => $form['note'] ?: null,
            'inform_with' => $form['inform_with'] ?: null,
        ]);
    }

    public function syncCarePlans(\App\Models\Person\Person $person, array $query = []): void
    {
        $response = EHealth::carePlan()->getSummary($person->uuid, $query);
        $data = $response->getData();

        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        $validator = \Illuminate\Support\Facades\Validator::make($data['data'], [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'required|string',
            '*.title' => 'required|string',
            '*.description' => 'nullable|string',
            '*.note' => 'nullable|string',
            '*.category' => 'nullable|array',
            '*.category.coding' => 'nullable|array',
            '*.period' => 'nullable|array',
            '*.period.start' => 'nullable|date',
            '*.period.end' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }
        $validData = $validator->validated();

        $activityRepo = app(CarePlanActivityRepository::class);

        foreach ($validData as $index => $item) {
            $rawFhir = $data['data'][$index];

            \App\Models\MedicalEvents\Mongo\CarePlan::updateOrCreate(
                ['uuid' => $item['id']],
                ['data' => $rawFhir]
            );

            $periodStart = isset($item['period']['start']) ? \Carbon\Carbon::parse($item['period']['start']) : null;
            $periodEnd = isset($item['period']['end']) ? \Carbon\Carbon::parse($item['period']['end']) : null;

            $category = null;
            if (isset($item['category']['text'])) {
                $category = $item['category']['text'];
            } elseif (isset($item['category']['coding'][0]['display'])) {
                $category = $item['category']['coding'][0]['display'];
            } elseif (isset($item['category']['coding'][0]['code'])) {
                $category = $item['category']['coding'][0]['code'];
            }

            $carePlan = CarePlan::updateOrCreate(
                ['uuid' => $item['id']],
                [
                    'person_id' => $person->id,
                    'status' => $item['status'],
                    'category' => $category,
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'note' => $item['note'] ?? null,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]
            );

            // Trigger sync for activities directly for each plan found active or relevant
            $activityRepo->syncActivities($person, $carePlan);
        }
    }
}
