<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\CarePlan\CarePlanCreate;

class CarePlanCreateHarness extends CarePlanCreate
{
    public function searchPatients(string $firstName, string $lastName, string $birthDate): array
    {
        return parent::searchPatients($firstName, $lastName, $birthDate);
    }

    public function carePlanEncounterChoices(int $personId, bool $forCreation = false): array
    {
        return parent::carePlanEncounterChoices($personId, $forCreation);
    }
}
