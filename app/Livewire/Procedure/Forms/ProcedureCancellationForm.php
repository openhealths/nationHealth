<?php

declare(strict_types=1);

namespace App\Livewire\Procedure\Forms;

use App\Core\BaseForm;
use App\Rules\InDictionary;

class ProcedureCancellationForm extends BaseForm
{
    public string $statusReason = '';

    public ?string $explanatoryLetter = null;

    public function cancellationRules(): array
    {
        return [
            'statusReason' => ['required', 'string', new InDictionary('eHealth/procedure_status_reasons')],
            'explanatoryLetter' => ['required', 'string', 'max:255'],
        ];
    }

    public function resetCancellationFields(): void
    {
        $this->statusReason = '';
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
