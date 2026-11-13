<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Livewire\EncounterPackage\EncounterPackageEdit;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class EncounterPackageView extends EncounterPackageEdit
{
    /**
     * Edit page of a draft that is not waiting for eHealth.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $editUrl = null;

    /**
     * Load the encounter package for viewing, offering its edit page while it can still be changed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Encounter  $encounter
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, Encounter $encounter, ?Person $person = null, ?Preperson $preperson = null): void
    {
        parent::mount($legalEntity, $encounter, $person, $preperson);

        // The edit page leaves only a draft that is not waiting for eHealth editable
        $this->editUrl = $this->isReadonly ? null : $this->editPageUrl();

        $this->isReadonly = true;
    }

    /**
     * Offer the additional actions once the submitted encounter was processed and finished.
     *
     * @return void
     */
    #[On('submission-processed')]
    public function showAdditionalActions(): void
    {
        $this->isFinished = Encounter::whereKey($this->encounterId)->value('status') === EncounterStatus::FINISHED;
        if ($this->isFinished) {
            $this->promptReferralCompletion();
        }
    }

    /**
     * Offer the edit page once eHealth rejected the submitted package, which stays a draft to fix.
     *
     * @return void
     */
    #[On('submission-rejected')]
    public function offerEditing(): void
    {
        $this->editUrl = $this->editPageUrl();
    }

    /**
     * Get the edit page of the encounter.
     *
     * @return string
     */
    private function editPageUrl(): string
    {
        return route(
            $this->prepersonId !== null ? 'prepersons.encounters.edit' : 'persons.encounters.edit',
            $this->prepersonId !== null
                ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' => $this->encounterId]
                : [legalEntity(), 'person' => $this->personId, 'encounter' => $this->encounterId]
        );
    }

    /**
     * Include issued referrals, the incoming referral and referrals referenced by the encounter's EMRs.
     * context_id is a FHIR Identifier FK, so match its UUID value rather than the local encounter ID.
     */
    #[Computed]
    public function electronicReferrals(): array
    {
        if ($this->personId === null || Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            return [];
        }

        $patient = $this->patient();
        $encounter = Encounter::forPatient($patient)->whereKey($this->encounterId)
            ->with('incomingReferral')->firstOrFail();
        if (Auth::user()->cannot('view', [$encounter, $patient])) {
            return [];
        }

        $linkedUuids = collect([$encounter->incomingReferral?->value]);
        foreach ([Procedure::class, DiagnosticReport::class] as $model) {
            $records = $model::forPatient($patient)->forEncounter($encounter->uuid)->with('basedOn')->get();
            foreach ($records as $record) {
                if (Auth::user()->can('view', [$record, $patient])) {
                    $linkedUuids->push($record->basedOn?->value);
                }
            }
        }

        $linkedUuids = $linkedUuids->filter()->unique()->values()->all();

        return ServiceRequestRequest::query()->wherePersonId($this->personId)
            ->where(function (Builder $query) use ($encounter, $linkedUuids): void {
                $query->whereHas('context', function (Builder $identifier) use ($encounter): void {
                    $identifier->whereValue($encounter->uuid)
                        ->where(function (Builder $type): void {
                            $type->whereDoesntHave('type.coding')
                                ->orWhereHas('type.coding', fn (Builder $coding): Builder => $coding->whereCode('encounter'));
                        });
                })->orWhereIn('uuid', $linkedUuids);
            })
            ->with(['person', 'employee', 'requesterLegalEntity', 'basedOn', 'context', 'category.coding', 'priority', 'programReference'])
            ->withExists(['ehealthLinks as submission_pending' => fn (Builder $link): Builder => $link->whereStatus(JobStatus::PENDING->value)])
            ->latest('id')->get()
            ->filter(fn (ServiceRequestRequest $request): bool => Auth::user()->can('view', $request))
            ->unique('uuid')
            ->map(fn (ServiceRequestRequest $request): array => Repository::serviceRequest()->toPatientRegistryRow($request))
            ->values()->all();
    }

    protected function referralCards(): array
    {
        return array_map(fn (array $referral): array => [
            'id' => $referral['id'],
            'title' => $referral['itemName'],
            'subtitle' => $referral['statusLabel'],
            'uppercaseLabels' => false,
            'detailRows' => [[
                'gridClass' => 'grid-cols-1 md:grid-cols-3',
                'fields' => [
                    ['label' => __('referrals.requisition'), 'value' => $referral['requestNumber'] ?: $referral['uuid']],
                    ['label' => __('referrals.period'), 'value' => $referral['periodLabel']],
                    ['label' => __('referrals.quantity'), 'value' => $referral['quantity']],
                ],
            ]],
        ], $this->electronicReferrals);
    }

    /**
     * Render the encounter package form, which is readonly here.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.encounter-package.encounter-package-view', [
            'electronicReferrals' => $this->electronicReferrals,
            'additionalActionRecords' => ['outgoing-referrals' => $this->referralCards()],
        ]);
    }
}
