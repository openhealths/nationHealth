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
     * Key container in base64. It is read in the browser and passed as an argument of the signing action.
     * It is not public, so Livewire does not return it in the component snapshot and it lasts for the current request only.
     *
     * @var string
     */
    protected string $keyContainer = '';

    /**
     * Key container password, passed and kept like the key container.
     *
     * @var string
     */
    protected string $password = '';

    public function signingRules(): array
    {
        return [
            'knedp' => ['required', 'string'],
            'password' => ['required', 'string'],
            'keyContainer' => ['required', 'string', 'max:' . static::KEY_CONTAINER_MAX_LENGTH]
        ];
    }

    /**
     * Take the key container and password passed to the signing action for the current request.
     *
     * @param  string  $keyContainer  Key container in base64
     * @param  string  $password
     * @return void
     */
    public function useSigningSecrets(string $keyContainer, string $password): void
    {
        $this->keyContainer = $keyContainer;
        $this->password = $password;
    }

    /**
     * Add the key container and password to the validated data: Livewire collects only public properties.
     *
     * @param  array  $attributes
     * @return array
     */
    protected function prepareForValidation($attributes): array
    {
        return [...$attributes, 'keyContainer' => $this->keyContainer, 'password' => $this->password];
    }
}
