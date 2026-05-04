<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;
use Throwable;

class PatientVaccination extends BasePatientComponent
{
    public array $immunizations = [];

    public string $filterVaccine = '';

    public string $filterEcozId = '';

    public string $filterMedicalRecordId = '';

    public string $filterEpisodeId = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public bool $showAdditionalParams = false;

    protected function initializeComponent(): void
    {
        $this->getImmunizations();
    }

    public function render(): View
    {
        return view('livewire.person.records.vaccination', [
            'immunizations' => $this->immunizations,
        ]);
    }

    public function getImmunizations(): void
    {
        $this->loadImmunizations();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        if (trim($this->filterEcozId) !== '') {
            $this->searchByEcozId();
            return;
        }

        $this->loadImmunizations($this->buildSearchParams());
    }

    public function syncVaccinations(): void
    {
        $this->search();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterVaccine',
            'filterEcozId',
            'filterMedicalRecordId',
            'filterEpisodeId',
            'filterDateFrom',
            'filterDateTo',
        ]);

        $this->getImmunizations();
    }

    private function searchByEcozId(): void
    {
        try {
            $response = EHealth::immunization()->getById(
                $this->uuid,
                trim($this->filterEcozId)
            );

            $validatedData = $response->validate();

            $items = $this->applyLocalFilters([$validatedData]);

            $this->immunizations = $this->prepareImmunizationsForView($items);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->immunizations = [];

            $this->handleEHealthExceptions($exception, 'Error while searching immunization by ESOZ ID');
        }
    }

    private function loadImmunizations(array $params = []): void
    {
        try {
            $response = EHealth::immunization()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

            $validatedData = $this->applyLocalFilters($validatedData);

            $this->immunizations = $this->prepareImmunizationsForView($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->immunizations = [];

            $this->handleEHealthExceptions($exception, 'Error while loading immunizations');
        }
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'vaccine_code' => $this->filterVaccine ?: null,
            'encounter_id' => $this->filterMedicalRecordId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'date_from' => $this->normalizeDateForApi($this->filterDateFrom),
            'date_to' => $this->normalizeDateForApi($this->filterDateTo),
            'page' => 1,
            'page_size' => 50,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function applyLocalFilters(array $immunizations): array
    {
        return array_values(array_filter($immunizations, function (array $immunization): bool {
            if (trim($this->filterMedicalRecordId) !== '') {
                $needle = mb_strtolower(trim($this->filterMedicalRecordId));

                $context = mb_strtolower(json_encode(
                    $immunization['context'] ?? [],
                    JSON_UNESCAPED_UNICODE
                ) ?: '');

                if (!str_contains($context, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function prepareImmunizationsForView(array $immunizations): array
    {
        return Arr::toCamelCase(
            $this->formatDatesForDisplay(
                Repository::immunization()->formatForView(
                    $this->normalizeImmunizationsForView($immunizations)
                )
            )
        );
    }

    private function normalizeImmunizationsForView(array $immunizations): array
    {
        return array_map(static function (array $immunization): array {
            $immunization['doseQuantity'] ??= [
                'value' => null,
                'code' => null,
                'unit' => null,
            ];

            $immunization['explanation'] ??= [];
            $immunization['explanation']['reasons'] ??= [];
            $immunization['explanation']['reasonsNotGiven'] ??= [];

            $immunization['reportOrigin'] ??= [
                'coding' => [
                    ['code' => ''],
                ],
            ];

            $immunization['vaccinationProtocols'] ??= [];
            $immunization['reactions'] ??= [];

            return $immunization;
        }, $immunizations);
    }

    private function filterValidationRules(): array
    {
        return [
            'filterVaccine' => ['nullable', 'string', 'max:255'],
            'filterEcozId' => ['nullable', 'uuid'],
            'filterMedicalRecordId' => ['nullable', 'uuid'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterDateFrom' => ['nullable', 'string', 'max:255'],
            'filterDateTo' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function parseDateRange(string $range): array
    {
        if (trim($range) === '') {
            return [null, null];
        }

        $dates = preg_split('/\s*-\s*/', $range);

        if (!$dates || count($dates) < 2) {
            return [null, null];
        }

        return [
            $this->normalizeDateForApi($dates[0]),
            $this->normalizeDateForApi($dates[1]),
        ];
    }

    private function normalizeDateForApi(string $date): ?string
    {
        $date = trim($date);

        if ($date === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
                return CarbonImmutable::createFromFormat('d.m.Y', $date)?->format('Y-m-d');
            }

            return CarbonImmutable::parse($date)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}