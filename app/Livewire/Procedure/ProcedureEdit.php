<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Throwable;

class ProcedureEdit extends ProcedureComponent
{
    #[Locked]
    public int $procedureId;

    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?int $procedureId = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->procedureId = $procedureId;

        $procedure = Procedure::withAllRelations()
            ->whereKey($procedureId)
            ->forPatient($this->patient())
            ->firstOrFail();

        $this->procedureUuid = $procedure->uuid;
        $this->isReadonly = request()->routeIs('*procedure.view');

        $procedureData = $procedure->toArray();

        $conditionUuids = collect(data_get($procedureData, 'reasonReferences', []))
            ->filter(fn (array $reference) => data_get($reference, 'identifier.type.coding.0.code') === 'condition')
            ->pluck('identifier.value')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $observationUuids = collect(data_get($procedureData, 'reasonReferences', []))
            ->filter(fn (array $reference) => data_get($reference, 'identifier.type.coding.0.code') === 'observation')
            ->pluck('identifier.value')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $complicationUuids = collect(data_get($procedureData, 'complicationDetails', []))
            ->pluck('identifier.value')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $detailsMap = array_merge(
            Repository::condition()->getProcedureReferenceDetailsMapByUuids($conditionUuids),
            Repository::condition()->getProcedureReferenceDetailsMapByUuids($complicationUuids),
            Repository::observation()->getDetailsMapByUuids($observationUuids)
        );

        $this->form->procedure = Fhir::procedure()->fromFhir($procedureData, $detailsMap);

        $this->loadIcd10Descriptions($this->form->procedure['reasonReferences'] ?? []);
    }

    /**
     * @throws Throwable
     */
    protected function persist(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
            Repository::procedure()->sync($this->patient(), [$this->fhirToSync($formattedData)]);

            return $this->procedureId;
        });
    }

    private function fhirToSync(array $procedure): array
    {
        $procedure['uuid'] = $procedure['id'];
        unset($procedure['id']);

        return Arr::toSnakeCase($procedure);
    }
}
