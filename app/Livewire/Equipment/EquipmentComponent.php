<?php

declare(strict_types=1);

namespace App\Livewire\Equipment;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\Equipment\Ehealth as EhealthData;
use App\Dto\Equipment\Form as EquipmentFormData;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Equipment\Forms\EquipmentForm as Form;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EquipmentComponent extends Component
{
    use FormTrait;

    public Form $form;

    /**
     * List of device definition.
     *
     * @var array
     */
    public array $deviceDefinitions;

    /**
     * List of active divisions.
     *
     * @var array
     */
    public array $divisions;

    /**
     * List of parent equipments.
     *
     * @var array
     */
    public array $equipments;

    /**
     * Current user's employees allowed to be the recorder.
     *
     * @var array
     */
    public array $recorders = [];

    /**
     * Used to indicate is it edit page, if so update DB row instead of create new one.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $equipmentId = null;

    public array $dictionaryNames = ['device_definition_classification_type', 'equipment_status_reasons'];

    public function baseMount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $this->divisions = $legalEntity->divisions()->active()->get(['uuid', 'name'])->toArray();
        $this->equipments = $legalEntity->equipments()
            ->active()
            ->with('names:equipment_id,name,type')
            ->get(['id', 'uuid', 'status', 'availability_status'])
            ->map(static fn (Equipment $equipment) => [
                'uuid' => $equipment->uuid,
                'name' => $equipment->names->first()->name,
                'type' => $equipment->names->first()->type,
                'status' => $equipment->status,
                'availabilityStatus' => $equipment->availabilityStatus
            ])
            ->toArray();
    }

    /**
     * Load the current user's employees allowed to be the recorder, keep the already selected one if it is among them,
     * otherwise preselect the first one.
     *
     * @param  LegalEntity  $legalEntity
     * @return void
     */
    protected function loadRecorders(LegalEntity $legalEntity): void
    {
        $this->recorders = Auth::user()->employees()
            ->activeRecorders($legalEntity->id)
            ->get(['uuid', 'party_id', 'employee_type'])
            ->map(static fn (Employee $employee) => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName . ' (' . Role::from($employee->employeeType)->label() . ')'
            ])
            ->toArray();

        $recorderIds = array_column($this->recorders, 'uuid');

        if (!in_array($this->form->recorder ?? '', $recorderIds, true)) {
            $this->form->recorder = $recorderIds[0] ?? '';
        }
    }

    /**
     * Map equipment data and fill the form.
     *
     * @param  Equipment  $equipment
     * @return void
     */
    protected function loadEquipmentToForm(Equipment $equipment): void
    {
        $equipment->loadMissing(['names', 'recorder:id,uuid', 'division:id,uuid', 'parent:id,uuid']);

        $this->form->fill(objectMapper()->map($equipment, EquipmentFormData::class));
    }

    /**
     * Validate form, if valid return validated data.
     *
     * @return array|false
     */
    protected function validateForm(): array|false
    {
        try {
            return $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return false;
        }
    }

    /**
     * Send a request to the API; if successful, return it; otherwise, show and log errors.
     *
     * @return EHealthResponse|PromiseInterface|null
     */
    protected function createInEHealth(): EHealthResponse|PromiseInterface|null
    {
        $payload = objectMapper()->map($this->form, EhealthData::class)->toArray();

        try {
            return EHealth::equipment()->create($payload);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating equipment');

            return null;
        }
    }
}
