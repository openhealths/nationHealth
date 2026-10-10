<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Core\Arr;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Dto\Procedure\Form as ProcedureFormData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
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

        $this->form->procedure = app(ObjectMapperInterface::class)->map(new Collection($procedureData), new ProcedureFormData($detailsMap))->toArray();

        $focalDeviceIds = collect($this->form->procedure['focalDevice'] ?? [])
            ->pluck('manipulatedId')
            ->filter()
            ->unique()
            ->values();

        if (!empty($this->form->procedure['encounterId']) && $focalDeviceIds->isNotEmpty()) {
            $focalDevices = Device::forPatient($this->patient())
                ->whereIn('uuid', $focalDeviceIds)
                ->with('names')
                ->get()
                ->keyBy('uuid');

            $this->form->procedure['focalDevice'] = collect($this->form->procedure['focalDevice'])
                ->map(static function (array $focalDevice) use ($focalDevices): array {
                    $device = $focalDevices->get($focalDevice['manipulatedId']);

                    return [
                        ...$focalDevice,
                        'name' => $device?->names->first()?->value ?? '',
                        'serialNumber' => $device?->serialNumber ?? '',
                        'statusLabel' => $device?->status->label() ?? ''
                    ];
                })
                ->toArray();
        }

        if (!empty($this->form->procedure['basedOnIdentifier'])) {
            $this->form->procedure['isReferralAvailable'] = true;
            $this->form->procedure['referralType'] = 'electronic';

            $this->form->procedure['referralNumber'] = $this->loadSelectedElectronicReferral($this->form->procedure['basedOnIdentifier']);
        }

        $divisionUuid = data_get($this->form->procedure, 'divisionId');

        if ($divisionUuid && !collect($this->divisions)->contains('uuid', $divisionUuid)) {
            $divisionName = Division::query()->whereUuid($divisionUuid)->value('name')
                ?: data_get($procedureData, 'division.displayValue')
                ?: $divisionUuid;

            $this->divisions[] = ['uuid' => $divisionUuid, 'name' => $divisionName];
        }

        $this->loadIcd10Descriptions($this->form->procedure['reasonReferences'] ?? []);
    }

    protected function loadSelectedElectronicReferral(string $uuid): string
    {
        $referral = Repository::serviceRequest()->findByUuid($uuid);

        if ($referral === null) {
            return '';
        }

        $services = collect($this->dictionaries['custom/services'] ?? []);
        $procedureCategories = array_keys($this->dictionaries['eHealth/procedure_categories'] ?? []);
        $service = $services->firstWhere('id', $referral->serviceId);

        $this->availableReferrals = [
            [
                'id' => $referral->uuid,
                'requisition' => $referral->requestNumber ?? '',
                'category' => $referral->category ? __('care-plan.referral_category.'.$referral->category) : __('procedures.electronic_referral'),
                'service' => $service,
                'isProcedureAllowed' => $service !== null && in_array($service['category'] ?? null, $procedureCategories, true),
            ],
        ];

        return $referral->requestNumber ?? '';
    }

    /**
     * @throws Throwable
     */
    protected function persist(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
            $uuid = data_get($this->form->procedure, 'basedOnIdentifier');

            if (data_get($this->form->procedure, 'referralType') === 'electronic' && filled($uuid)) {
                $this->storeElectronicReferralIfMissing($uuid, Auth::user()->getProcedureWriterEmployee());
            }

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
