<?php

declare(strict_types=1);

namespace App\Core;

use Livewire\Form;

class BaseForm extends Form
{
    /**
     * Longest accepted key container in base64: a 1 MB key file. Real key files are a few kilobytes.
     */
    protected const int KEY_CONTAINER_MAX_LENGTH = 1_398_104;

    public string $knedp = '';

    /**
     * Key container in base64. It is read in the browser and arrives only with the signing request.
     *
     * @var string
     */
    public string $keyContainer = '';

    public string $password = '';

    public function signingRules(): array
    {
        return [
            'knedp' => ['required', 'string'],
            'password' => ['required', 'string'],
            'keyContainer' => ['required', 'string', 'max:' . static::KEY_CONTAINER_MAX_LENGTH]
        ];
    }

    /**
     * Clear the signing credentials so that they never outlive a single request.
     *
     * @return void
     */
    public function resetSigningFields(): void
    {
        $this->knedp = '';
        $this->password = '';
        $this->keyContainer = '';
    }
}
