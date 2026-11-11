<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\InformWith;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

class EncounterReferralCreate extends EncounterDocumentCreate
{
    use ManagesEncounterReferrals;

    #[Locked]
    public ?string $submissionId = null;

    #[Locked]
    public ?int $referralId = null;

    #[Locked]
    public bool $referralReadOnly = false;

    #[Locked]
    public bool $prepareReferralForSigning = false;

    #[Locked]
    public array $referralSummary = [];

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?Encounter $encounter = null, ?int $referral = null): void
    {
        $this->referralId = $referral;
        $this->referralReadOnly = request()->routeIs('persons.encounters.referrals.show');
        parent::mount($legalEntity, $person, $preperson, $encounter);
    }

    protected function documentAbility(): string
    {
        return $this->referralReadOnly ? 'view' : 'createServiceRequest';
    }

    protected function initializeComponent(): void
    {
        $referralId = $this->referralId;
        $submissionId = $referralId !== null ? (string) $referralId : request()->query('submission');
        if (!$this->referralReadOnly) {
            $this->openEncounterReferralDrawer();
        }
        $this->referralId = $referralId;
        if (!is_string($submissionId) || (!ctype_digit($submissionId) && !Str::isUuid($submissionId))) {
            return;
        }
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }
        $submissionUuid = ctype_digit($submissionId)
            ? Repository::serviceRequest()->findForPatient((int) $submissionId, $this->patient())->uuid
            : $submissionId;
        $record = app(MedicalRequestOwnership::class)->serviceForEncounter($submissionUuid, $encounter);
        if ($this->referralReadOnly && Auth::user()->cannot('viewRecord', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));
            $this->returnToEncounter();

            return;
        }
        $resource = Repository::serviceRequest()->getResource($record);
        $this->encounterReferralRequestIdToSign = $record->uuid;
        $fields = (new ServiceRequestMapper())->fromFhir($resource);
        if ($this->referralReadOnly) {
            $this->encounterReferralForm = $fields + ['care_plan_id' => 0, 'care_plan_activity_id' => 0, 'inform_with' => '', 'performer_type' => '', 'location_reference' => ''];
        }
        foreach (array_keys($this->encounterReferralForm) as $field) {
            if (array_key_exists($field, $fields)) {
                $this->encounterReferralForm[$field] = $fields[$field] ?? $this->encounterReferralForm[$field];
            }
        }
        $this->encounterReferralForm['program_id'] = $fields['program_id'] ?? '';
        $activity = filled($fields['based_on_uuid'] ?? null)
            ? Repository::serviceRequest()->activityContext($fields['based_on_uuid'], (int) $this->personId)
            : [];
        if ($activity !== []) {
            // Keep the saved basis even when access expired; save/sign must reject it rather than silently detach it.
            $this->encounterReferralForm['care_plan_id'] = $activity['care_plan_id'];
            $this->encounterReferralForm['care_plan_activity_id'] = $activity['care_plan_activity_id'];
        }
        $authMethodId = data_get($resource, 'informWith.authMethodId');
        $authMethod = collect($this->encounterReferralAuthMethods)->first(
            static fn (array $method): bool => ($method['uuid'] ?? null) === $authMethodId
        );
        $this->encounterReferralForm['inform_with'] = $authMethod !== null
            ? InformWith::formValue($authMethod)
            : (string) $authMethodId;
        foreach (['started_at' => 'start_time', 'ended_at' => 'end_time'] as $dateField => $timeField) {
            if (!empty($fields[$dateField])) {
                $date = CarbonImmutable::parse($fields[$dateField])->setTimezone(config('app.timezone'));
                $this->encounterReferralForm[$dateField] = $date->format('d.m.Y');
                $this->encounterReferralForm[$timeField] = $date->format('H:i');
            }
        }
        $service = dictionary()->services()->flattened()->firstWhere('id', $fields['service_id']);
        $this->encounterReferralServiceType = $fields['service_type'] ?? 'service';
        $this->encounterReferralSelectedService = [
            'id' => $fields['service_id'],
            'code' => $service['code'] ?? $fields['service_id'],
            'name' => $service['name'] ?? (data_get($resource, 'code.displayValue') ?? data_get($resource, 'code.display', __('encounters.service'))),
            'type' => $this->encounterReferralServiceType,
        ];
        $this->encounterReferralRequestIdToSign = $record->uuid;
        $this->submissionId = (string) $record->id;
        $this->referralId = (int) $record->id;
        $this->referralReadOnly = !in_array(ServiceRequestStatus::resolve((string) $record->status), [ServiceRequestStatus::DRAFT, ServiceRequestStatus::NEW], true);
        if ($this->referralReadOnly) {
            $this->referralSummary = ['status' => ServiceRequestStatus::labelFor((string) $record->status), 'number' => $record->requestNumber];

            return;
        }
        $this->checkEncounterReferralSubmission();
        if ($this->encounterReferralRequestIdToSign === null) {
            return;
        }
        $this->prepareReferralForSigning = session()->pull('encounter_referral_prepare_signature.'.$record->id, false)
            && empty($this->encounterReferralSubmission['status']);
        if (!$this->prepareReferralForSigning && $record->status === 'draft' && empty($this->encounterReferralSubmission['status'])
            && !session()->has('success') && !session()->has('info')
            && !session()->has('error') && !session()->has('warning')) {
            $start = $record->startedAt;
            if ($start !== null && $start->isPast()) {
                session()->flash('warning', __('referrals.draft_start_in_past', [
                    'start' => $start->format('d.m.Y H:i'),
                ]));
            } else {
                session()->flash('info', __('referrals.draft_found_for_signing'));
            }
        }
    }

    public function prepareSavedReferralForSigning(): void
    {
        if (!$this->prepareReferralForSigning || $this->referralId === null) {
            return;
        }
        $this->prepareReferralForSigning = false;
        $this->validateEncounterReferral();
    }

    public function sign(): void
    {
        if ($this->actionType === 'sign_referral') {
            $this->signEncounterReferral();
        }
    }

    protected function afterEncounterReferralDraftChanged(): void
    {
        $record = $this->encounterReferralRequestIdToSign !== null
            ? Repository::serviceRequest()->findByUuid($this->encounterReferralRequestIdToSign)
            : null;
        $this->submissionId = $record === null ? null : (string) $record->id;
        if ($record === null) {
            $this->referralId = null;
        }
    }

    protected function afterEncounterReferralDraftSaved(bool $sign): bool
    {
        if ($this->referralId !== null || $this->submissionId === null) {
            return false;
        }
        $this->referralId = (int) $this->submissionId;
        if ($sign) {
            session()->flash('success', __('referrals.draft_saved'));
            session()->flash('encounter_referral_prepare_signature.'.$this->submissionId, true);
        }
        $this->redirectToDraft();

        return true;
    }

    protected function afterEncounterReferralSubmitted(): void
    {
        $this->redirectToDraft();
    }

    private function redirectToDraft(): void
    {
        $this->redirectRoute($this->prepersonId !== null ? 'prepersons.encounter.referral.edit' : 'persons.encounters.referrals.edit', [
            'legalEntity' => $this->legalEntityId,
            $this->prepersonId !== null ? 'preperson' : 'person' => $this->prepersonId ?? $this->personId,
            'encounter' => $this->encounterId,
            'referral' => $this->submissionId,
        ], navigate: true);
    }

    protected function afterEncounterReferralSigned(ServiceRequestRequest $record): void
    {
        $this->returnToEncounter();
    }

    public function render(): View
    {
        return view('livewire.encounter-package.encounter-referral-create');
    }
}
