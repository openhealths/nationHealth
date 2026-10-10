<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Throwable;

class DeviceDispenses extends BasePatientComponent
{
    public array $filters = ['based_on' => '', 'status' => '', 'encounter' => '', 'context_episode_id' => '', 'performer_legal_entity' => '', 'performer' => '', 'device_code' => '', 'device' => '', 'program' => '', 'when_handed_over_from' => '', 'when_handed_over_to' => ''];
    #[Locked]
    public array $dispenses = [];
    #[Locked]
    public array $details = [];
    public int $page = 1;
    #[Locked]
    public int $totalPages = 1;

    protected function initializeComponent(): void
    {
        app(DeviceRequestLifecycleService::class)->authorizeAction(legalEntity(), 'device_dispense:read');
        $this->search();
    }

    public function search(bool $resetPage = true): void
    {
        app(DeviceRequestLifecycleService::class)->authorizeAction(legalEntity(), 'device_dispense:read');
        $rules = [];
        foreach (['based_on', 'encounter', 'context_episode_id', 'performer_legal_entity', 'performer', 'device', 'program'] as $key) {
            $rules['filters.'.$key] = 'nullable|uuid';
        }
        $rules += ['filters.status' => 'nullable|in:active,in_progress,completed,stopped,entered-in-error', 'filters.device_code' => 'nullable|string|max:100', 'filters.when_handed_over_from' => 'nullable|date', 'filters.when_handed_over_to' => 'nullable|date'];
        if (!empty($this->filters['when_handed_over_from'])) {
            $rules['filters.when_handed_over_to'] .= '|after_or_equal:filters.when_handed_over_from';
        }
        $this->validate($rules);
        if ($resetPage) {
            $this->page = 1;
        }
        try {
            $response = EHealth::deviceRequest()->getDispenses($this->uuid, array_filter($this->filters) + ['page' => $this->page]);
            $this->dispenses = $response->getData();
            $this->totalPages = (int) ($response->getPaging()['total_pages'] ?? 1);
        } catch (Throwable $exception) {
            $this->dispenses = [];
            report($exception);
            Session::flash('error', $exception->getMessage());
        }
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($this->totalPages, $page));
        $this->search(false);
    }

    public function showDetails(string $id): void
    {
        app(DeviceRequestLifecycleService::class)->authorizeAction(legalEntity(), 'device_dispense:read');
        abort_unless(Str::isUuid($id), 404);
        try {
            $this->details = EHealth::deviceRequest()->getDispense($this->uuid, $id)->getData();
        } catch (Throwable $exception) {
            $this->details = [];
            report($exception);
            Session::flash('error', $exception->getMessage());
        }
    }

    public function resetFilters(): void
    {
        $this->filters = array_fill_keys(array_keys($this->filters), '');
        $this->search();
    }

    public function render(): View
    {
        return view('livewire.person.records.device-dispenses');
    }
}
