<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Concerns;

use App\Classes\eHealth\EHealth;
use App\Models\MedicalEvents\Sql\Composition;
use App\Repositories\MedicalEvents\Repository;

trait InteractsWithCompositions
{
    protected function fetchComposition(Composition $composition): array
    {
        if (!$composition->hasReadContext) {
            return [];
        }

        return EHealth::composition()->getById(
            $composition->patientUuid,
            $composition->uuid,
            $composition->episodeOfCareUuid,
            $composition->encounterUuid
        )->validate();
    }

    protected function fetchIntegration(Composition $composition): array
    {
        if (!$composition->hasReadContext) {
            return [];
        }

        return EHealth::composition()->getIntegrationData(
            $composition->patientUuid,
            $composition->uuid,
            $composition->episodeOfCareUuid,
            $composition->encounterUuid
        )->validate();
    }

    protected function syncIntegration(Composition $composition): array
    {
        $items = $this->fetchIntegration($composition);
        Repository::composition()->storeIntegration($composition, $items);

        return $items;
    }
}
