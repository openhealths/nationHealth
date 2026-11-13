<?php

declare(strict_types=1);

namespace App\Livewire\Referral\Forms;

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

    /** Fill only editable fields from the persisted resource, never from browser-owned FHIR data. */
    public function setReferral(array $resource): void
    {
        $this->reset();
        $this->fill([
            'note' => $resource['note'] ?? null,
            'patientInstruction' => $resource['patientInstruction'] ?? null,
            'priority' => $resource['priority'] ?? 'routine',
        ]);
    }
}
