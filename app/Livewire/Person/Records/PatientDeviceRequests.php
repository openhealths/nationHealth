<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Enums\MedicalProgram\Type;
use App\Livewire\DeviceRequest\DeviceRequestIndex;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

class PatientDeviceRequests extends DeviceRequestIndex
{
    public function mount(LegalEntity $legalEntity, ?Person $person = null): void
    {
        abort_unless($person?->exists, 404);
        parent::mount($legalEntity, $person);
    }

    #[Computed]
    public function canReadRequests(): bool
    {
        return Auth::user()?->can('viewAny', DeviceRequestRequest::class) ?? false;
    }

    public function search(bool $resetPage = true): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }
        $this->filters->validateOnly('parameters.requester_legal_entity');
        $this->requesterEntitySearch = $this->filters->parameters['requester_legal_entity'];

        if ($this->filters->parameters['status'] === 'draft') {
            abort_unless($this->person?->exists, 404);
            $this->filters->toQuery(localDraft: true);
            $this->clearDetails();
            $this->requests = [];
            $this->page = $this->totalPages = 1;
        } else {
            parent::search($resetPage);
        }

        $this->drafts = [];
        if (!in_array($this->filters->parameters['status'], ['', 'draft'], true)
            || !Auth::user()->can('create', DeviceRequestRequest::class)) {
            return;
        }

        app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');
        $this->drafts = Repository::deviceRequest()->draftsForPatientUser($this->personId, Auth::user(), $this->legalEntity)
            ->map(static fn (DeviceRequestRequest $draft): array => array_merge($draft->requestPayload, [
                'uuid' => $draft->uuid, 'status' => 'draft', 'isDraft' => true,
                'authored_on' => $draft->createdAt->toIso8601String(),
            ]))
            ->filter(function (array $draft): bool {
                foreach (['program' => 'program.identifier.value', 'encounter' => 'encounter.identifier.value',
                    'context_episode_id' => 'context.identifier.value', 'requester_legal_entity' => 'requester_legal_entity.identifier.value'] as $filter => $path) {
                    if ($this->filters->parameters[$filter] !== '' && data_get($draft, $path) !== $this->filters->parameters[$filter]) {
                        return false;
                    }
                }

                return $this->filters->parameters['code'] === '' || collect(data_get($draft, 'code.coding', []))->contains('code', $this->filters->parameters['code']);
            })->values()->all();
    }

    #[Computed]
    public function deviceTypes(): array
    {
        return dictionary()->basics()->byName('eHealth/assistive_devices')->flattenedChildValues()->toArray()
            + dictionary()->basics()->byName('device_definition_classification_type')->flattenedChildValues()->toArray();
    }

    #[Computed]
    public function programs(): array
    {
        return dictionary()->medicalPrograms()->where('is_active', true)->where('type', Type::DEVICE->value)->values()->toArray();
    }

    public function render()
    {
        return view('livewire.person.records.device-requests', [
            'person' => $this->person,
            'records' => array_merge($this->drafts, $this->requests),
            'deviceTypes' => $this->deviceTypes,
            'programs' => $this->programs,
            'canReadRequests' => $this->canReadRequests,
        ])->layout('layouts.app');
    }
}
