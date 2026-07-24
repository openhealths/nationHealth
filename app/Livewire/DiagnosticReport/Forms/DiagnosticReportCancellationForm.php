<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport\Forms;

use App\Core\BaseForm;
use App\Rules\InDictionary;

class DiagnosticReportCancellationForm extends BaseForm
{
    public string $cancellationReason = '';

    public ?string $explanatoryLetter = null;

    public function cancellationRules(): array
    {
        return [
            'cancellationReason' => ['required', 'string', new InDictionary('eHealth/cancellation_reasons')],
            'explanatoryLetter' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function resetCancellationFields(): void
    {
        $this->cancellationReason = '';
        $this->explanatoryLetter = null;
    }

    public function resetSigningFields(): void
    {
        if (isset($this->knedp)) {
            $this->knedp = '';
        }

        if (isset($this->password)) {
            $this->password = '';
        }

        if (isset($this->keyContainerUpload)) {
            unset($this->keyContainerUpload);
        }
    }

    protected function rules(): array
    {
        return $this->cancellationRules();
    }
}
