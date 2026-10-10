<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Episode\Status;
use App\Livewire\Encounter\Concerns\BuildsEncounterPackage;
use App\Livewire\Encounter\Concerns\LoadsEncounterPackage;

/** Test access to the exact protected workflows used by the Livewire components. */
class EncounterPackageHarness
{
    use BuildsEncounterPackage;

    use LoadsEncounterPackage;

    public function toFhir(array $data, array $uuids): array
    {
        return $this->mapEncounterPackage($data, $uuids);
    }

    public function build(array $data, string $episodeType, Status $status = Status::ACTIVE): array
    {
        return $this->buildEncounterPackage($data, $episodeType, $status);
    }

    public function load(array $encounter): array
    {
        return $this->loadEncounterPackage($encounter);
    }
}
