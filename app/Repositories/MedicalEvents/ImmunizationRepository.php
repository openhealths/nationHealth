<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\ImmunizationDoseQuantity;
use App\Models\MedicalEvents\Sql\ImmunizationExplanation;
use App\Models\MedicalEvents\Sql\ImmunizationVaccinationProtocol;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImmunizationRepository extends BaseRepository
{
    /**
     * Store condition in DB.
     *
     * @param  array  $data
     * @param  int  $personId
     * @param  int  $encounterId
     * @return void
     * @throws Throwable
     */
    public function store(array $data, int $personId, int $encounterId): void
    {
        DB::transaction(function () use ($data, $personId, $encounterId) {
            try {
                foreach ($data as $datum) {
                    $vaccineCode = Repository::codeableConcept()->store($datum['vaccineCode']);

                    $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                    Repository::codeableConcept()->attach($context, $datum['context']);

                    if (isset($datum['performer'])) {
                        $performer = Repository::identifier()->store($datum['performer']['identifier']['value']);
                        Repository::codeableConcept()->attach($performer, $datum['performer']);
                    }

                    if (isset($datum['reportOrigin'])) {
                        $reportOrigin = Repository::codeableConcept()->store($datum['reportOrigin']);
                    }

                    if (isset($datum['site'])) {
                        $site = Repository::codeableConcept()->store($datum['site']);
                    }

                    if (isset($datum['route'])) {
                        $route = Repository::codeableConcept()->store($datum['route']);
                    }

                    /** @var Immunization $immunization */
                    $immunization = $this->model::create([
                        'uuid' => $datum['uuid'] ?? $datum['id'],
                        'person_id' => $personId,
                        'encounter_id' => $encounterId,
                        'status' => $datum['status'],
                        'not_given' => $datum['notGiven'],
                        'vaccine_code_id' => $vaccineCode->id,
                        'context_id' => $context->id,
                        'date' => $datum['date'] ?? null,
                        'primary_source' => $datum['primarySource'],
                        'performer_id' => $performer->id ?? null,
                        'report_origin_id' => $reportOrigin->id ?? null,
                        'manufacturer' => $datum['manufacturer'] ?? null,
                        'lot_number' => $datum['lotNumber'] ?? null,
                        'expiration_date' => $datum['expirationDate'] ?? null,
                        'site_id' => $site->id ?? null,
                        'route_id' => $route->id ?? null
                    ]);

                    if (isset($datum['doseQuantity'])) {
                        ImmunizationDoseQuantity::create([
                            'immunization_id' => $immunization->id,
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

                            ImmunizationExplanation::create([
                                'immunization_id' => $immunization->id,
                                'reasons_id' => $reasons->id,
                                'reasons_not_given_id' => null
                            ]);
                        }
                    }

                    if (isset($datum['explanation']['reasonsNotGiven'])) {
                        foreach ($datum['explanation']['reasonsNotGiven'] as $reasonNotGiven) {
                            $reasonsNotGiven = Repository::codeableConcept()->store($reasonNotGiven);

                            ImmunizationExplanation::create([
                                'immunization_id' => $immunization->id,
                                'reasons_id' => null,
                                'reasons_not_given_id' => $reasonsNotGiven->id
                            ]);
                        }
                    }

                    if (!empty($datum['vaccinationProtocols'])) {
                        foreach ($datum['vaccinationProtocols'] as $vaccinationProtocolData) {
                            $authority = Repository::codeableConcept()->store($vaccinationProtocolData['authority']);

                            $immunizationVaccinationProtocol = ImmunizationVaccinationProtocol::create([
                                'immunization_id' => $immunization->id,
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
            } catch (Exception $e) {
                Log::channel('db_errors')->error('Error saving condition', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);

                throw $e;
            }
        });
    }

    /**
     * Get condition data that is related to the encounter.
     *
     * @param  int  $encounterId
     * @return array|null
     */
    public function get(int $encounterId): ?array
    {
        return $this->model::with([
            'vaccineCode.coding',
            'context.type.coding',
            'performer.type.coding',
            'reportOrigin.coding',
            'site.coding',
            'route.coding',
            'doseQuantity',
            'vaccinationProtocols.authority.coding',
            'vaccinationProtocols.targetDiseases.coding'
        ])
            ->where('encounter_id', $encounterId)
            ->get()
            ?->toArray();
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
            // Get UUIDs from API data
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            // Load existing immunizations with relations
            $existingImmunizations = $this->model::whereIn('uuid', $apiUuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingImmunizations->get($data['uuid']);

                // Sync relationships
                $vaccineCode = $this->syncCodeableConcept($existing, $data['vaccine_code'], 'vaccineCode');
                $context = $this->syncIdentifier($existing, $data['context'], 'context');
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $site = $this->syncCodeableConcept($existing, $data['site'] ?? null, 'site');
                $route = $this->syncCodeableConcept($existing, $data['route'] ?? null, 'route');

                $immunization = $this->model::updateOrCreate(
                    ['uuid' => $data['uuid']],
                    array_merge(
                        [
                            'person_id' => $personId,
                            'vaccine_code_id' => $vaccineCode->id,
                            'context_id' => $context->id,
                            'performer_id' => $performer?->id,
                            'report_origin_id' => $reportOrigin?->id,
                            'site_id' => $site?->id,
                            'route_id' => $route?->id
                        ],
                        Arr::except($data, [
                            'vaccine_code',
                            'context',
                            'report_origin',
                            'site',
                            'route',
                            'dose_quantity',
                            'explanation',
                            'reactions',
                            'vaccination_protocols'
                        ])
                    )
                );

                $this->syncDoseQuantity($immunization, $data['dose_quantity'] ?? null);
                $this->syncExplanations($immunization, $existing, $data['explanation'] ?? []);
                $this->syncReactions($existing, $data['reactions'], $immunization);
                $this->syncVaccinationProtocols($immunization, $existing, $data['vaccination_protocols'] ?? []);
            }
        });
    }

    /**
     * Sync immunization explanations (reasons and reasons not given).
     *
     * @param  Immunization  $immunization
     * @param  Immunization|null  $existing
     * @param  array  $explanationData
     * @return void
     */
    private function syncExplanations(Immunization $immunization, ?Immunization $existing, array $explanationData): void
    {
        $existingExplanations = $existing?->explanations ?? collect();

        // Sync reasons
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

        // Sync reasons_not_given
        $rngIds = [];
        if (!empty($explanationData['reasons_not_given'])) {
            $existingRng = $existingExplanations->whereNotNull('reasons_not_given_id')->pluck('reasonsNotGiven')->values();
            foreach ($explanationData['reasons_not_given'] as $index => $rngData) {
                $existingRng = $existingRng[$index] ?? null;
                if ($existingRng) {
                    $this->updateCodeableConcept($existingRng, $rngData);
                    $rngIds[] = $existingRng->id;
                } else {
                    $concept = Repository::codeableConcept()->store($rngData);
                    $rngIds[] = $concept->id;
                }
            }
        }

        // Sync pivot
        $immunization->explanations()->whereNotNull('reasons_id')
            ->whereNotIn('reasons_id', $reasonIds)->delete();
        $immunization->explanations()->whereNotNull('reasons_not_given_id')
            ->whereNotIn('reasons_not_given_id', $rngIds)->delete();

        foreach ($reasonIds as $reasonId) {
            $immunization->explanations()->firstOrCreate(['reasons_id' => $reasonId]);
        }

        foreach ($rngIds as $rngId) {
            $immunization->explanations()->firstOrCreate(['reasons_not_given_id' => $rngId]);
        }
    }

    /**
     * Sync immunization dose quantity.
     *
     * @param  Immunization  $immunization
     * @param  array|null  $doseQuantityData
     * @return void
     */
    private function syncDoseQuantity(Immunization $immunization, ?array $doseQuantityData): void
    {
        if ($doseQuantityData) {
            // Update or create dose quantity
            $immunization->doseQuantity()->updateOrCreate(
                ['immunization_id' => $immunization->id],
                [
                    'value' => $doseQuantityData['value'],
                    'comparator' => $doseQuantityData['comparator'] ?? null,
                    'unit' => $doseQuantityData['unit'] ?? null,
                    'system' => $doseQuantityData['system'] ?? null,
                    'code' => $doseQuantityData['code'] ?? null
                ]
            );
        } else {
            // Remove dose quantity if not provided
            $immunization->doseQuantity()->delete();
        }
    }

    private function syncReactions(?Immunization $existing, ?array $items, Immunization $parent): void
    {
        if (empty($items)) {
            return;
        }

        $existingReactions = $existing?->reactions ?? collect();

        foreach ($items as $index => $item) {
            $existingReaction = $existingReactions[$index] ?? null;

            if ($existingReaction) {
                // Update existing reaction
                $existingReaction->update(['display_value' => $item['display_value'] ?? null]);

                // Update identifier
                $identifier = $existingReaction->detail;
                if ($identifier) {
                    $this->updateIdentifier($identifier, $item['detail']);
                }
            } else {
                // Create new reaction
                $identifier = Repository::identifier()->store(
                    $item['detail']['identifier']['value'],
                    $item['detail']['display_value'] ?? null
                );
                Repository::codeableConcept()->attach($identifier, $item['detail']);

                $parent->reactions()->create([
                    'detail_id' => $identifier->id,
                    'display_value' => $item['display_value'] ?? null
                ]);
            }
        }
    }

    /**
     * Sync immunization vaccination protocols.
     *
     * @param  Immunization  $immunization
     * @param  Immunization|null  $existing
     * @param  array  $protocolsData
     * @return void
     */
    private function syncVaccinationProtocols(
        Immunization $immunization,
        ?Immunization $existing,
        array $protocolsData
    ): void {
        if (empty($protocolsData)) {
            return;
        }

        $existingProtocols = $existing?->vaccinationProtocols ?? collect();

        foreach ($protocolsData as $index => $protocolData) {
            $existingProtocol = $existingProtocols[$index] ?? null;

            // Sync authority
            $authority = null;
            if (isset($protocolData['authority'])) {
                if ($existingProtocol && $existingProtocol->authority) {
                    $this->updateCodeableConcept($existingProtocol->authority, $protocolData['authority']);
                    $authority = $existingProtocol->authority;
                } else {
                    $authority = Repository::codeableConcept()->store($protocolData['authority']);
                }
            }

            // Update or create vaccination protocol
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

            // Sync target diseases
            if (!empty($protocolData['target_diseases'])) {
                $targetDiseaseIds = $this->syncCodeableConcepts(
                    $existingProtocol,
                    $protocolData['target_diseases'],
                    'targetDiseases'
                );
                $vaccinationProtocol->targetDiseases()->sync($targetDiseaseIds);
            }
        }
    }
}
