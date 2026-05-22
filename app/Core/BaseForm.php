<?php

declare(strict_types=1);

namespace App\Core;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

class BaseForm extends Form
{
    public string $knedp;

    public TemporaryUploadedFile $keyContainerUpload;

    public string $password;

    public function signingRules(): array
    {
        return [
            'knedp' => ['required', 'string'],
            'password' => ['required', 'string'],
            'keyContainerUpload' => ['required', 'file', 'extensions:dat,pfx,pk8,zs2,jks,p7s']
        ];
    }
}
