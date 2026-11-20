<?php

declare(strict_types=1);

namespace App\Livewire\Referral\Forms;

use App\Dto\ServiceRequest\Form as ReferralFormData;
use Illuminate\Validation\Rule;
use Livewire\Form;

class EReferralForm extends Form
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

    public function setReferral(ReferralFormData $data): void
    {
        $this->reset();
        $this->fill([
            'note' => $data->note,
            'patientInstruction' => $data->patientInstruction,
            'priority' => $data->priority ?? 'routine',
        ]);
    }
}
