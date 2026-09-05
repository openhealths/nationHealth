<?php

declare(strict_types=1);

namespace App\Core;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

class BaseForm extends Form
{
    public string $knedp;

    public TemporaryUploadedFile $keyContainerUpload;

    public string $keyContainerFileName = '';

    public string $password;

    public function signingRules(): array
    {
        return [
            'knedp' => ['required', 'string'],
            'password' => ['required', 'string'],
            'keyContainerUpload' => ['required', 'file', 'extensions:dat,pfx,pk8,zs2,jks,p7s']
        ];
    }

    /**
     * Clear the signing credentials so that they never outlive a single request.
     *
     * @return void
     */
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

        $this->keyContainerFileName = '';
    }
}
