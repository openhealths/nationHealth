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

        return $this->fetchCompositionDetails(
            $composition->patientUuid,
            $composition->uuid,
            $composition->episodeOfCareUuid,
            $composition->encounterUuid,
            array_filter([
                'author.value' => $composition->authorUuid,
                'type.coding.0.code' => $composition->type?->value,
                'subject.type.coding.0.code' => $composition->prepersonId ? 'preperson' : 'person',
            ], static fn ($value): bool => $value !== null)
        );
    }

    /** Search summaries omit author; only validated full details can supply unknown metadata. */
    protected function fetchCompositionDetails(
        string $patientUuid,
        string $compositionUuid,
        string $episodeUuid,
        string $encounterUuid,
        array $knownContext
    ): array {
        $details = EHealth::composition()->getById($patientUuid, $compositionUuid, $episodeUuid, $encounterUuid)->validate();
        $expected = [
            'identifier.value' => $compositionUuid,
            'identifier.type.coding.0.code' => 'composition',
            'subject.value' => $patientUuid,
            'author.type.coding.0.code' => 'employee',
            'encounter.value' => $encounterUuid,
            'encounter.type.coding.0.code' => 'encounter',
        ] + $knownContext;
        // The native detail schema may omit episodeOfCare; its request URL always carries the exact episode.
        if (array_key_exists('episodeOfCare', $details)) {
            $expected['episodeOfCare.value'] = $episodeUuid;
            $expected['episodeOfCare.type.coding.0.code'] = 'episode';
        }
        Repository::composition()->assertDocumentContext($details, $expected);

        return $details;
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
