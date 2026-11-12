<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Classes\eHealth\EHealth;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\Composition\TreatmentViolation;
use App\Jobs\CompositionAsyncProcessing;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Shared validated document reads, queue dispatch and display transformations for conclusions. */
abstract class CompositionComponent extends BasePatientComponent
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
        EHealth::composition()->assertDocumentContext($details, $expected);

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

    /** Queue dispatch belongs to the component that accepted the validated remote request. */
    protected function queueCompositionOperation(CompositionOperation $operation): void
    {
        (new CompositionAsyncProcessing($operation, legalEntity()))->dispatchProcessing();
    }

    public function compositionStatus(?string $status): ?CompositionStatus
    {
        return filled($status) ? CompositionStatus::tryFrom(str_replace('-', '_', strtoupper($status))) : null;
    }

    public function treatmentViolationLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '-';
        }

        $label = dictionary()->basics()->byName(TreatmentViolation::DICTIONARY)->asCodeDescription()->get($code);

        return is_string($label) && $label !== '' ? $label : $code;
    }

    public function compositionExtensions(array $detail): Collection
    {
        return collect(data_get($detail, 'extension', []))
            ->filter(static fn ($extension): bool => is_array($extension) && isset($extension['valueCode']))
            ->mapWithKeys(static fn (array $extension): array => [
                $extension['valueCode'] => $extension['valueUuid']
                    ?? ($extension['valueDate']
                        ?? ($extension['valueString']
                            ?? ($extension['valueBoolean'] ?? null))),
            ]);
    }

    public function compositionDate(?string $value): string
    {
        return $value ? CarbonImmutable::parse($value)->format(config('app.date_format')) : '-';
    }

    public function compositionDisplay(array $detail): array
    {
        return [
            'status' => $this->compositionStatus(data_get($detail, 'status')),
            'type' => CompositionType::tryFrom((string) data_get($detail, 'type.coding.0.code')),
            'category' => CompositionCategory::tryFrom((string) data_get($detail, 'category.coding.0.code')),
            'extensions' => $this->compositionExtensions($detail),
        ];
    }
}
