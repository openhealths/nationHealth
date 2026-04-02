<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
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
            // Load existing immunizations with relations
            $uuids = collect($validatedData)->pluck('uuid')->toArray();
            $existingImmunizations = $this->model::whereIn('uuid', $uuids)
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingImmunizations->get($data['uuid']);

                // Store relationships
                $vaccineCode = Repository::codeableConcept()->store($data['vaccine_code']);

                $context = Repository::identifier()->store($data['context']['identifier']['value']);
                Repository::codeableConcept()->attach($context, $data['context']);

                $performer = null;
                if (isset($data['performer'])) {
                    $performer = Repository::identifier()->store($data['performer']['identifier']['value']);
                    Repository::codeableConcept()->attach($performer, $data['performer']);
                }

                $reportOrigin = null;
                if (isset($data['report_origin'])) {
                    $reportOrigin = Repository::codeableConcept()->store($data['report_origin']);
                }

                $site = null;
                if (isset($data['site'])) {
                    $site = Repository::codeableConcept()->store($data['site']);
                }

                $route = null;
                if (isset($data['route'])) {
                    $route = Repository::codeableConcept()->store($data['route']);
                }

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
                        'route_id' => $route?->id,
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

                // Sync dose quantity
                if (isset($data['dose_quantity'])) {
                    $immunization->doseQuantity()->delete();
                    $immunization->doseQuantity()->create([
                        'value' => $data['dose_quantity']['value'],
                        'comparator' => $data['dose_quantity']['comparator'] ?? null,
                        'unit' => $data['dose_quantity']['unit'] ?? null,
                        'system' => $data['dose_quantity']['system'] ?? null,
                        'code' => $data['dose_quantity']['code'] ?? null
                    ]);
                }

                // Sync explanations (reasons)
                $immunization->explanations()->delete();
                if (isset($data['explanation']['reasons'])) {
                    foreach ($data['explanation']['reasons'] as $reasonData) {
                        $reasons = Repository::codeableConcept()->store($reasonData);

                        $immunization->explanations()->create([
                            'reasons_id' => $reasons->id,
                            'reasons_not_given_id' => null
                        ]);
                    }
                }

                // Sync explanations (reasons not given)
                if (isset($data['explanation']['reasons_not_given'])) {
                    foreach ($data['explanation']['reasons_not_given'] as $reasonNotGiven) {
                        $reasonsNotGiven = Repository::codeableConcept()->store($reasonNotGiven);

                        $immunization->explanations()->create([
                            'reasons_id' => null,
                            'reasons_not_given_id' => $reasonsNotGiven->id
                        ]);
                    }
                }

                // Sync vaccination protocols
                $immunization->vaccinationProtocols()->delete();
                if (!empty($data['vaccination_protocols'])) {
                    foreach ($data['vaccination_protocols'] as $vaccinationProtocolData) {
                        $authority = Repository::codeableConcept()->store($vaccinationProtocolData['authority']);

                        $immunizationVaccinationProtocol = $immunization->vaccinationProtocols()->create([
                            'dose_sequence' => $vaccinationProtocolData['dose_sequence'] ?? null,
                            'description' => $vaccinationProtocolData['description'] ?? null,
                            'authority_id' => $authority->id ?? null,
                            'series' => $vaccinationProtocolData['series'] ?? null,
                            'series_doses' => $vaccinationProtocolData['series_doses'] ?? null
                        ]);

                        if (!empty($vaccinationProtocolData['target_diseases'])) {
                            $targetDiseaseIds = [];
                            foreach ($vaccinationProtocolData['target_diseases'] as $targetDiseaseData) {
                                $targetDisease = Repository::codeableConcept()->store($targetDiseaseData);
                                $targetDiseaseIds[] = $targetDisease->id;
                            }

                            $immunizationVaccinationProtocol->targetDiseases()->attach($targetDiseaseIds);
                        }
                    }
                }

                // Sync reactions
                $immunization->reactions()->delete();
                if (!empty($data['reactions'])) {
                    foreach ($data['reactions'] as $reactionData) {
                        $detail = Repository::identifier()->store($reactionData['detail']['identifier']['value']);
                        Repository::codeableConcept()->attach($detail, $reactionData['detail']);

                        $immunization->reactions()->create([
                            'detail_id' => $detail->id,
                            'display_value' => $reactionData['display_value'] ?? null
                        ]);
                    }
                }

                // Cleanup old relationships after all updates are done
                if ($existing) {
                    $this->cleanupRelations($existing);
                }
            }
        });
    }

    /**
     * Remove orphaned relations after immunization FK update.
     *
     * @param  Immunization  $existing
     * @return void
     */
    private function cleanupRelations(Immunization $existing): void
    {
        RelationshipCleaner::cleanRelations($existing, [
            'context' => 'identifier',
            'performer' => 'identifier',
            'vaccineCode' => 'codeable_concept',
            'reportOrigin' => 'codeable_concept',
            'site' => 'codeable_concept',
            'route' => 'codeable_concept',
        ]);

        // Handle complex nested collections
        foreach ($existing->explanations as $explanation) {
            RelationshipCleaner::cleanCodeableConceptRelation($explanation->reasons);
            RelationshipCleaner::cleanCodeableConceptRelation($explanation->reasonsNotGiven);
        }

        foreach ($existing->vaccinationProtocols as $protocol) {
            RelationshipCleaner::cleanCodeableConceptRelation($protocol->authority);
            RelationshipCleaner::cleanCodeableConceptCollection($protocol->targetDiseases);
        }

        foreach ($existing->reactions as $reaction) {
            RelationshipCleaner::cleanIdentifierRelation($reaction->detail);
        }
    }
}
