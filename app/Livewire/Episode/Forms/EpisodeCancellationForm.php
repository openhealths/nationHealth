<?php

declare(strict_types=1);

namespace App\Livewire\Episode\Forms;

use App\Rules\InDictionary;
use Livewire\Attributes\Locked;
use Livewire\Form;

class EpisodeCancellationForm extends Form
{
    /**
     * eHealth ID of the episode being marked as entered in error.
     *
     * @var string
     */
    #[Locked]
    public string $cancellingId = '';

    public string $cancellationReason = '';

    public string $explanatoryLetter = '';

    /**
     * Rules for marking an episode as entered in error.
     *
     * @return array
     */
    protected function rules(): array
    {
        return [
            'cancellationReason' => ['required', 'string', new InDictionary('eHealth/cancellation_reasons')],
            'explanatoryLetter' => ['nullable', 'string', 'max:255']
        ];
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
