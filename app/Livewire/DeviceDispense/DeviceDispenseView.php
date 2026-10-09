<?php

declare(strict_types=1);

namespace App\Livewire\DeviceDispense;

use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

class DeviceDispenseView extends BasePatientComponent
{
    /**
     * ID of the device dispense being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $deviceDispenseId;

    /**
     * Request-scoped memoized device dispense.
     *
     * @var DeviceDispense|null
     */
    private ?DeviceDispense $deviceDispenseModel = null;

    protected array $dictionaryNames = [
        'device_definition_classification_type',
        'eHealth/LOINC/observation_codes',
        'eHealth/ICF/classifiers',
        'eHealth/ICPC2/condition_codes'
    ];

    /**
     * Bind the route models and load the device dispense being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  DeviceDispense|null  $deviceDispense
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?DeviceDispense $deviceDispense = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->getDictionary();
        $this->loadCustomDictionaries();

        $this->deviceDispenseId = $deviceDispense->id;

        $this->deviceDispense();
    }
    
    /**
     * Load dictionaries that are not part of the standard basic dictionary list.
     *
     * @return void
     */
    protected function loadCustomDictionaries(): void
    {
        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()
            ->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();

        $this->dictionaries['custom/services'] = dictionary()
            ->services()
            ->flattened()
            ->toArray();
    }

    /**
     * Resolve the device dispense being displayed for the current patient.
     *
     * @return DeviceDispense
     */
    protected function deviceDispense(): DeviceDispense
    {
        return $this->deviceDispenseModel ??= DeviceDispense::forPatient($this->patient())
            ->withAllRelations()
            ->whereId($this->deviceDispenseId)
            ->firstOrFail();
    }

    /**
     * Resolve the device dispense medical device name.
     *
     * @param  DeviceDispense  $deviceDispense
     * @return string|null
     */
    protected function deviceName(DeviceDispense $deviceDispense): ?string
    {
        $detail = $deviceDispense->details->first();
        $deviceDefinitionId = $detail?->device?->value;
        $deviceCode = $detail?->deviceCode?->coding->first()?->code;

        if ($deviceDefinitionId) {
            $deviceDefinition = dictionary()
                ->deviceDefinitions()
                ->firstWhere('id', $deviceDefinitionId);

            return data_get($deviceDefinition, 'device_names.0.name') ?? $deviceDefinitionId;
        }

        return data_get($this->dictionaries, 'device_definition_classification_type.' . $deviceCode);
    }

    /**
     * Resolve the performer full name.
     *
     * @param  DeviceDispense  $deviceDispense
     * @return string|null
     */
    protected function performerName(DeviceDispense $deviceDispense): ?string
    {
        if ($deviceDispense->performer?->displayValue) {
            return $deviceDispense->performer->displayValue;
        }

        $uuid = $deviceDispense->performer?->value;

        if ($uuid === null) {
            return null;
        }

        return Employee::with('party:id,last_name,first_name,second_name')
            ->firstWhere('uuid', $uuid)?->fullName ?? $uuid;
    }

    /**
     * Resolve the performer legal entity name.
     *
     * @param  DeviceDispense  $deviceDispense
     * @return string|null
     */
    protected function legalEntityName(DeviceDispense $deviceDispense): ?string
    {
        if ($deviceDispense->performerLegalEntity?->displayValue) {
            return $deviceDispense->performerLegalEntity->displayValue;
        }

        $uuid = $deviceDispense->performerLegalEntity?->value;

        if ($uuid === null) {
            return null;
        }

        return LegalEntity::query()->whereUuid($uuid)->first()?->name ?? $uuid;
    }

    /**
     * Resolve the dispense location name.
     *
     * @param  DeviceDispense  $deviceDispense
     * @return string|null
     */
    protected function locationName(DeviceDispense $deviceDispense): ?string
    {
        if ($deviceDispense->location?->displayValue) {
            return $deviceDispense->location->displayValue;
        }

        $uuid = $deviceDispense->location?->value;

        if ($uuid === null) {
            return null;
        }

        return Division::query()->whereUuid($uuid)->value('name') ?? $uuid;
    }

    /**
     * Resolve the linked procedure name.
     *
     * @param  DeviceDispense  $deviceDispense
     * @return string|null
     */
    protected function procedureName(DeviceDispense $deviceDispense): ?string
    {
        $uuid = $deviceDispense->partOf?->value;

        if ($uuid === null) {
            return null;
        }

        $procedure = Procedure::forPatient($this->patient())
            ->whereUuid($uuid)
            ->first();

        if ($procedure === null) {
            return $uuid;
        }

        return __('procedures.label') . ' ' . $procedure->created_at->format(config('app.date_format'));
    }

    /**
     * Prepare supporting medical records for display.
     *
     * @param  DeviceDispense  $deviceDispense
     * @return array
     */
    private function supportingInfo(DeviceDispense $deviceDispense): array
    {
        $supportingInfo = $deviceDispense->supportingInfo;

        if ($supportingInfo->isEmpty()) {
            return [];
        }

        $uuidsByType = $supportingInfo
            ->groupBy(
                static fn ($supporting): ?string =>
                    $supporting->type->first()?->coding->first()?->code
            )
            ->map(
                static fn ($group): array => $group
                    ->pluck('value')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray()
            );

        $detailsMap = array_merge(
            Repository::condition()->getDetailsMapByUuids($uuidsByType->get('condition', [])),
            Repository::observation()->getDetailsMapByUuids($uuidsByType->get('observation', [])),
            Repository::diagnosticReport()->getDetailsMapByUuids($uuidsByType->get('diagnostic_report', [])),
            Repository::procedure()->getDetailsMapByUuids($uuidsByType->get('procedure', [])),
            Repository::encounter()->getDetailsMapByUuids($uuidsByType->get('encounter', [])),
            Repository::episode()->getDetailsMapByUuids($uuidsByType->get('episode', []))
        );

        return $supportingInfo
            ->map(static function ($supporting) use ($detailsMap): array {
                $uuid = $supporting->value;
                $details = $detailsMap[$uuid] ?? [];

                return [
                    'uuid' => $uuid,
                    'type' => $supporting->type->first()?->coding->first()?->code,
                    'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                    'code' => $details['codeCode'] ?? null
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Render the device dispense details page.
     *
     * @return View
     */
    public function render(): View
    {
        $deviceDispense = $this->deviceDispense();

        return view('livewire.device-dispense.device-dispense-view')->with([
            'deviceDispense' => $deviceDispense,
            'deviceName' => $this->deviceName($deviceDispense),
            'performerName' => $this->performerName($deviceDispense),
            'legalEntityName' => $this->legalEntityName($deviceDispense),
            'locationName' => $this->locationName($deviceDispense),
            'procedureName' => $this->procedureName($deviceDispense),
            'supportingInfo' => $this->supportingInfo($deviceDispense),
        ]);
    }
}