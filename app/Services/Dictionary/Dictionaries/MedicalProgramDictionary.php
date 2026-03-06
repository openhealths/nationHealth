<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Dictionaries;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\Dictionary\DictionaryInterface;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;

class MedicalProgramDictionary implements DictionaryInterface
{
    use FormTrait;

    /**
     * Dictionary unique identifier key.
     */
    public const string KEY = 'dictionaries.medical_program';

    /**
     * Get the dictionary key.
     *
     * @return string Dictionary identifier for caching and registry
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Fetch medical programs from eHealth API as MIS.
     *
     * Retrieves all available medical programs with their configurations,
     * statuses, and associated medical services.
     *
     * @return array Raw medical program data from eHealth API
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function fetch(): array
    {
        return EHealth::medicalProgram()->asMis()->getMany()->getData();
    }
}
