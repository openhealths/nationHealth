<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\ImmunizationReaction;
use App\Models\MedicalEvents\Sql\ImmunizationVaccinationProtocol;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Immunization $model
 */
class ImmunizationRepository extends BaseRepository
{
    /**
     * Store condition in DB.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId): void
    {
        DB::transaction(function () use ($data, $personId) {
            foreach ($data as $datum) {
                $vaccineCode = Repository::codeableConcept()->store($datum['vaccineCode']);

                $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                Repository::codeableConcept()->attach($context, $datum['context']);

                $performer = null;
                if (isset($datum['performer'])) {
                    $performer = Repository::identifier()->store($datum['performer']['identifier']['value']);
                    Repository::codeableConcept()->attach($performer, $datum['performer']);
                }

                $immunization = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    'person_id' => $personId,
                    'status' => $datum['status'],
                    'not_given' => $datum['notGiven'],
                    'vaccine_code_id' => $vaccineCode->id,
                    'context_id' => $context->id,
                    'date' => $datum['date'] ?? null,
                    'primary_source' => $datum['primarySource'],
                    'performer_id' => $performer?->id,
                    'report_origin_id' => isset($datum['reportOrigin'])
                        ? Repository::codeableConcept()->store($datum['reportOrigin'])->id
                        : null,
                    'manufacturer' => $datum['manufacturer'] ?? null,
                    'lot_number' => $datum['lotNumber'] ?? null,
                    'expiration_date' => $datum['expirationDate'] ?? null,
                    'site_id' => isset($datum['site'])
                        ? Repository::codeableConcept()->store($datum['site'])->id
                        : null,
                    'route_id' => isset($datum['route'])
                        ? Repository::codeableConcept()->store($datum['route'])->id
                        : null
                ]);

                if (isset($datum['doseQuantity'])) {
                    $immunization->doseQuantity()->create([
                        'value' => $datum['doseQuantity']['value'],
                        'comparator' => $datum['doseQuantity']['comparator'] ?? null,
                        'unit' => $datum['doseQuantity']['unit'] ?? null,
                        'system' => $datum['doseQuantity']['system'] ?? null,
                        'code' => $datum['doseQuantity']['code'] ?? null
                    ]);
                }

                if (isset($datum['explanation']['reasons'])) {
                    foreach ($datum['explanation']['reasons'] as $reasonData) {
                        $reasons = Repository::codeableConcept()->store($reasonData);

                        $immunization->explanations()->create([
                            'reasons_id' => $reasons->id,
                            'reasons_not_given_id' => null
                        ]);
                    }
                }

                if (isset($datum['explanation']['reasonsNotGiven'])) {
                    foreach ($datum['explanation']['reasonsNotGiven'] as $reasonNotGiven) {
                        $reasonsNotGiven = Repository::codeableConcept()->store($reasonNotGiven);

                        $immunization->explanations()->create([
                            'reasons_id' => null,
                            'reasons_not_given_id' => $reasonsNotGiven->id
                        ]);
                    }
                }

                if (!empty($datum['vaccinationProtocols'])) {
                    foreach ($datum['vaccinationProtocols'] as $vaccinationProtocolData) {
                        $authority = Repository::codeableConcept()->store($vaccinationProtocolData['authority']);

                        $immunizationVaccinationProtocol = $immunization->vaccinationProtocols()->create([
                            'dose_sequence' => $vaccinationProtocolData['doseSequence'] ?? null,
                            'description' => $vaccinationProtocolData['description'] ?? null,
                            'authority_id' => $authority->id ?? null,
                            'series' => $vaccinationProtocolData['series'] ?? null,
                            'series_doses' => $vaccinationProtocolData['seriesDoses'] ?? null
                        ]);

                        $targetDiseaseIds = [];
                        foreach ($vaccinationProtocolData['targetDiseases'] as $targetDiseaseData) {
                            $targetDisease = Repository::codeableConcept()->store($targetDiseaseData);

                            $targetDiseaseIds[] = $targetDisease->id;
                        }

                        $immunizationVaccinationProtocol->targetDiseases()->attach($targetDiseaseIds);
                    }
                }
            }
        });
    }

    /**
     * Get immunization data that is related to the encounter.
     *
     * @param  string  $encounterUuid
     * @return array|null
     */
    public function get(string $encounterUuid): ?array
    {
        return $this->model->withAllRelations()
            ->whereHas('context', fn ($query) => $query->where('value', $encounterUuid))
            ->get()
            ?->toArray();
    }

    /**
     * Get immunization data that is related to the person.
     *
     * @param  string  $personId
     * @return array|null
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->withAllRelations()
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    /**
     * Get immunization data that is related to the person with pagination.
     *
     * @param  int  $personId
     * @param  int  $page
     * @param  int  $pageSize
     * @return array
     */
    public function getByPersonIdPaginated(int $personId, int $page, int $pageSize): array
    {
        return $this->model
            ->withAllRelations()
            ->where('person_id', $personId)
            ->orderByDesc('date')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->toArray();
    }

    /**
     * Get count of immunization data that is related to the person.
     *
     * @param  int  $personId
     * @return array|null
     */
    public function countByPersonId(int $personId): int 
    {
        return $this->model
            ->where('person_id', $personId)
            ->count();
    }

    /**
     * Formatting immunizations to show on the frontend.
     *
     * @param  array  $immunizations
     * @return array
     */
    public function formatForView(array $immunizations): array
    {
        return array_map(static function (array $immunization) {
            if (empty($immunization['explanation']['reasons'])) {
                $immunization['explanation']['reasons'] = [];
            }

            if (empty($immunization['explanation']['reasonsNotGiven'])) {
                $immunization['explanation']['reasonsNotGiven'] = [];
            }

            if (empty($immunization['reportOrigin'])) {
                $immunization['reportOrigin'] = [
                    'coding' => [
                        ['code' => '']
                    ]
                ];
            }

            if (is_null($immunization['doseQuantity'])) {
                $immunization['doseQuantity']['value'] = null;
                $immunization['doseQuantity']['code'] = null;
            }

            return $immunization;
        }, $immunizations);
    }

    /**
     * Sync immunization data and related data by deleting and creating.
     *
     * @param  int  $personId
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(int $personId, array $validatedData): void
    {
        DB::transaction(function () use ($personId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingImmunizations = $this->model->whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingImmunizations->get($data['uuid']);

                $vaccineCode = $this->syncCodeableConcept($existing, $data['vaccine_code'], 'vaccineCode');
                $context = $this->syncIdentifier($existing, $data['context'], 'context');
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $site = $this->syncCodeableConcept($existing, $data['site'] ?? null, 'site');
                $route = $this->syncCodeableConcept($existing, $data['route'] ?? null, 'route');

                $immunizationData = [
                    'person_id' => $personId,
                    'status' => $data['status'],
                    'not_given' => $data['not_given'],
                    'vaccine_code_id' => $vaccineCode->id,
                    'context_id' => $context->id,
                    'date' => $data['date'],
                    'primary_source' => $data['primary_source'],
                    'performer_id' => $performer?->id,
                    'report_origin_id' => $reportOrigin?->id,
                    'manufacturer' => $data['manufacturer'] ?? null,
                    'lot_number' => $data['lot_number'] ?? null,
                    'expiration_date' => $data['expiration_date'] ?? null,
                    'site_id' => $site?->id,
                    'route_id' => $route?->id
                ];

                if ($existing) {
                    $existing->update($immunizationData);
                    $immunization = $existing;
                } else {
                    $immunization = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $immunizationData)
                    );
                }

                $this->syncDoseQuantity($immunization, $data['dose_quantity'] ?? []);
                $this->syncExplanations($immunization, $data['explanation'] ?? []);
                $this->syncReactions($immunization, $data['reactions'] ?? []);
                $this->syncVaccinationProtocols($immunization, $data['vaccination_protocols'] ?? []);
            }
        });
    }

    /**
     * Sync immunization explanations (reasons and reasons not given).
     *
     * @param  Immunization  $immunization
     * @param  array  $explanationData
     * @return void
     */
    private function syncExplanations(Immunization $immunization, array $explanationData): void
    {
        $existingExplanations = $immunization->relationLoaded('explanations')
            ? $immunization->explanations
            : collect();

        $reasonIds = [];
        if (!empty($explanationData['reasons'])) {
            $existingReasons = $existingExplanations->whereNotNull('reasons_id')->pluck('reasons')->values();
            foreach ($explanationData['reasons'] as $index => $reasonData) {
                $existingReason = $existingReasons[$index] ?? null;
                if ($existingReason) {
                    $this->updateCodeableConcept($existingReason, $reasonData);
                    $reasonIds[] = $existingReason->id;
                } else {
                    $concept = Repository::codeableConcept()->store($reasonData);
                    $reasonIds[] = $concept->id;
                }
            }
        }

        $rngIds = [];
        if (!empty($explanationData['reasons_not_given'])) {
            $existingRng = $existingExplanations->whereNotNull('reasons_not_given_id')
                ->pluck('reasonsNotGiven')
                ->values();
            foreach ($explanationData['reasons_not_given'] as $index => $rngData) {
                $existingRngItem = $existingRng[$index] ?? null;
                if ($existingRngItem) {
                    $this->updateCodeableConcept($existingRngItem, $rngData);
                    $rngIds[] = $existingRngItem->id;
                } else {
                    $concept = Repository::codeableConcept()->store($rngData);
                    $rngIds[] = $concept->id;
                }
            }
        }

        if ($immunization->wasRecentlyCreated) {
            foreach ($reasonIds as $reasonId) {
                $immunization->explanations()->create(['reasons_id' => $reasonId]);
            }
            foreach ($rngIds as $rngId) {
                $immunization->explanations()->create(['reasons_not_given_id' => $rngId]);
            }
        } else {
            $currentReasonIds = $existingExplanations->whereNotNull('reasons_id')->pluck('reasons_id')->toArray();
            $currentRngIds = $existingExplanations->whereNotNull('reasons_not_given_id')
                ->pluck('reasons_not_given_id')
                ->toArray();

            if (array_diff($currentReasonIds, $reasonIds) || array_diff($reasonIds, $currentReasonIds)) {
                $immunization->explanations()->whereNotNull('reasons_id')->delete();
                foreach ($reasonIds as $reasonId) {
                    $immunization->explanations()->create(['reasons_id' => $reasonId]);
                }
            }

            if (array_diff($currentRngIds, $rngIds) || array_diff($rngIds, $currentRngIds)) {
                $immunization->explanations()->whereNotNull('reasons_not_given_id')->delete();
                foreach ($rngIds as $rngId) {
                    $immunization->explanations()->create(['reasons_not_given_id' => $rngId]);
                }
            }
        }
    }

    /**
     * Sync immunization dose quantity.
     *
     * @param  Immunization  $immunization
     * @param  array  $doseQuantityData
     * @return void
     */
    private function syncDoseQuantity(Immunization $immunization, array $doseQuantityData): void
    {
        $doseQuantity = $immunization->relationLoaded('doseQuantity') ? $immunization->doseQuantity : null;

        if (empty($doseQuantityData)) {
            $doseQuantity?->delete();

            return;
        }

        if ($doseQuantity) {
            $doseQuantity->update([
                'value' => $doseQuantityData['value'],
                'comparator' => $doseQuantityData['comparator'] ?? null,
                'unit' => $doseQuantityData['unit'],
                'system' => $doseQuantityData['system'] ?? null,
                'code' => $doseQuantityData['code'] ?? null
            ]);
        } else {
            $immunization->doseQuantity()->create([
                'value' => $doseQuantityData['value'],
                'comparator' => $doseQuantityData['comparator'] ?? null,
                'unit' => $doseQuantityData['unit'],
                'system' => $doseQuantityData['system'] ?? null,
                'code' => $doseQuantityData['code'] ?? null
            ]);
        }
    }

    /**
     * Sync immunization reactions array.
     *
     * @param  Immunization  $immunization
     * @param  array  $reactions
     * @return void
     */
    private function syncReactions(Immunization $immunization, array $reactions): void
    {
        $existingReactions = $immunization->relationLoaded('reactions')
            ? $immunization->reactions
            : collect();

        if (empty($reactions)) {
            $existingReactions->each(fn (ImmunizationReaction $reaction) => $reaction->delete());

            return;
        }

        foreach ($reactions as $index => $reaction) {
            $existingReaction = $existingReactions[$index] ?? null;

            if ($existingReaction) {
                $existingReaction->update(['display_value' => $reaction['display_value'] ?? null]);

                $identifier = $existingReaction->detail;
                if ($identifier) {
                    $this->updateIdentifier($identifier, $reaction['detail']);
                }
            } else {
                $identifier = Repository::identifier()->store(
                    $reaction['detail']['identifier']['value'],
                    $reaction['detail']['display_value'] ?? null
                );
                Repository::codeableConcept()->attach($identifier, $reaction['detail']);

                $immunization->reactions()->create([
                    'detail_id' => $identifier->id,
                    'display_value' => $reaction['display_value'] ?? null
                ]);
            }
        }

        foreach ($existingReactions->slice(count($reactions)) as $extra) {
            $extra->delete();
        }
    }

    /**
     * Sync immunization vaccination protocols.
     *
     * @param  Immunization  $immunization
     * @param  array  $protocolsData
     * @return void
     */
    private function syncVaccinationProtocols(Immunization $immunization, array $protocolsData): void
    {
        $existingProtocols = $immunization->relationLoaded('vaccinationProtocols')
            ? $immunization->vaccinationProtocols
            : collect();

        if (empty($protocolsData)) {
            $existingProtocols->each(fn (ImmunizationVaccinationProtocol $protocol) => $protocol->delete());

            return;
        }

        foreach ($protocolsData as $index => $protocolData) {
            $existingProtocol = $existingProtocols[$index] ?? null;

            $authority = null;
            if (isset($protocolData['authority'])) {
                if ($existingProtocol && $existingProtocol->authority) {
                    $this->updateCodeableConcept($existingProtocol->authority, $protocolData['authority']);
                    $authority = $existingProtocol->authority;
                } else {
                    $authority = Repository::codeableConcept()->store($protocolData['authority']);
                }
            }

            if ($existingProtocol) {
                $existingProtocol->update([
                    'dose_sequence' => $protocolData['dose_sequence'] ?? null,
                    'description' => $protocolData['description'] ?? null,
                    'authority_id' => $authority?->id,
                    'series' => $protocolData['series'] ?? null,
                    'series_doses' => $protocolData['series_doses'] ?? null
                ]);
                $vaccinationProtocol = $existingProtocol;
            } else {
                $vaccinationProtocol = $immunization->vaccinationProtocols()->create([
                    'dose_sequence' => $protocolData['dose_sequence'] ?? null,
                    'description' => $protocolData['description'] ?? null,
                    'authority_id' => $authority?->id,
                    'series' => $protocolData['series'] ?? null,
                    'series_doses' => $protocolData['series_doses'] ?? null
                ]);
            }

            if (!empty($protocolData['target_diseases'])) {
                $this->syncPivot(
                    $vaccinationProtocol,
                    'targetDiseases',
                    $this->syncCodeableConcepts($existingProtocol, $protocolData['target_diseases'], 'targetDiseases')
                );
            }
        }

        foreach ($existingProtocols->slice(count($protocolsData)) as $extra) {
            $extra->delete();
        }
    }
}
