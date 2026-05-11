<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;

class PatientImmunization extends BasePatientComponent
{
    public array $immunizations = [];

    public array $episodes = [];

    public string $filterVaccine = '';

    public string $filterEpisodeId = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public bool $showAdditionalParams = false;

    protected function initializeComponent(): void
    {
        $this->loadEpisodes();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->loadImmunizations($this->buildSearchParams());
    }

    public function sync(): void
    {
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterVaccine',
            'filterEpisodeId',
            'filterDateFrom',
            'filterDateTo',
        ]);

        $this->loadImmunizations();
    }

    private function loadImmunizations(array $params = []): void
    {
        try {
            $response = EHealth::immunization()->getBySearchParams($this->uuid, $params);

            $validatedData = $response->validate();

            $this->immunizations = Arr::toCamelCase($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->immunizations = [];

            $this->handleEHealthExceptions($exception, 'Error while loading immunizations');
        }
    }

    private function loadEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()?->uuid]
            );

            $validatedData = $response->validate();

            $this->episodes = Arr::toCamelCase($validatedData);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->episodes = [];

            $this->handleEHealthExceptions($exception, 'Error while loading episodes');
        }
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'vaccine_code' => $this->filterVaccine ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'date_from' => $this->filterDateFrom ?: null,
            'date_to' => $this->filterDateTo ?: null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function filterValidationRules(): array
    {
        return [
            'filterVaccine' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterDateFrom' => ['nullable', 'string', 'max:255'],
            'filterDateTo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.immunization');
    }
}