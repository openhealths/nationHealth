<?php

declare(strict_types=1);

namespace App\Livewire\ServiceRequestRequest\Forms;

use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Form;

class ServiceRequestRequestForm extends Form
{
    public ?string $note = null;

    public ?string $patientInstruction = null;

    public string $priority = 'routine';

    protected function rules(): array
    {
        return [
            'note' => ['nullable', 'string'],
            'patientInstruction' => ['nullable', 'string'],
            'priority' => ['required', Rule::in(array_keys($this->priorityOptions()))],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'note' => __('referrals.registry.note'),
            'patientInstruction' => __('referrals.registry.patient_instruction'),
            'priority' => __('referrals.table.priority'),
        ];
    }

    public function priorityOptions(): array
    {
        return dictionary()->basics()->byName('SERVICE_REQUEST_PRIORITY')->asCodeDescription()->all();
    }

    public function validateForUpdate(ServiceRequestRequest $serviceRequest, string $requestVersion): void
    {
        $this->withValidator(function (Validator $validator) use ($serviceRequest, $requestVersion): void {
            $validator->after(function (Validator $validator) use ($serviceRequest, $requestVersion): void {
                if ($serviceRequest->requestVersion() !== $requestVersion) {
                    $validator->errors()->add('request', __('referrals.messages.action_unavailable'));
                }
            });
        })->validate();
    }
}
