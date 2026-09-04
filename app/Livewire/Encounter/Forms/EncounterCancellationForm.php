<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Core\BaseForm;
use App\Rules\InDictionary;
use Livewire\Attributes\Locked;

class EncounterCancellationForm extends BaseForm
{
    /**
     * eHealth ID of the encounter whose package is being marked as entered in error.
     *
     * @var string
     */
    #[Locked]
    public string $cancellingId = '';

    public string $cancellationReason = '';

    public string $explanatoryLetter = '';

    /**
     * Rules for marking an encounter package as entered in error.
     *
     * @return array
     */
    public function cancellationRules(): array
    {
        return [
            'cancellationReason' => ['required', 'string', new InDictionary('eHealth/cancellation_reasons')],
            'explanatoryLetter' => ['required', 'string', 'max:255']
        ];
    }

    /**
     * Clear the reason and the explanation kept between two openings of the modal.
     *
     * @return void
     */
    public function resetCancellationFields(): void
    {
        $this->cancellingId = '';
        $this->cancellationReason = '';
        $this->explanatoryLetter = '';
    }

    /**
     * Redefine field names for error messages.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return [
            'cancellationReason' => __('medical-events.cancel_modal.reason_label'),
            'explanatoryLetter' => __('medical-events.cancel_modal.explanation_label')
        ];
    }
}
