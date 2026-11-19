<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\InformWith;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
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

    #[Locked]
    public array $encounterReferralReferenceOptions = [];

    #[Locked]
    public array $encounterReferralOriginalReferences = [];

    #[Locked]
    public array $encounterReferralEpisodes = [];

    #[Locked]
    public int $encounterReferralEpisodePage = 1;

    #[Locked]
    public bool $encounterReferralEpisodesHasMore = false;

    #[Locked]
    public int $encounterReferralReferencePage = 1;
    #[Locked]
    public bool $encounterReferralReferenceHasMore = false;
    #[Locked]
    public string $encounterReferralReferenceScope = 'supporting_info';
    #[Locked]
    public string $encounterReferralReferenceSearch = '';
    #[Locked]
    public string $encounterReferralReferenceType = '';
    #[Locked]
    public string $encounterReferralReferenceEpisode = '';

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?Encounter $encounter = null, ?int $referral = null): void
    {
        $this->referralId = $referral;
        parent::mount($legalEntity, $person, $preperson, $encounter);
    }

    protected function documentAbility(): string
    {
        return 'createServiceRequest';
    }

    protected function initializeComponent(): void
    {
        $referralId = $this->referralId;
        $submissionId = $referralId !== null ? (string) $referralId : request()->query('submission');
        $this->openEncounterReferralDrawer();
        $this->referralId = $referralId;
        if (!is_string($submissionId) || (!ctype_digit($submissionId) && !Str::isUuid($submissionId))) {
            return;
        }
        $encounter = $this->resolveEncounterModelForStandalone();
        $submissionUuid = ctype_digit($submissionId)
            ? Repository::serviceRequest()->findForPerson((int) $submissionId, (int) $this->personId)->uuid
            : $submissionId;
        $record = app(MedicalRequestOwnership::class)->serviceForEncounter($submissionUuid, $encounter);
        $resource = Repository::serviceRequest()->getResource($record);
        $fields = (new ServiceRequestMapper())->fromFhir($resource);
        foreach (array_keys($this->encounterReferralForm) as $field) {
            if (array_key_exists($field, $fields)) {
                $this->encounterReferralForm[$field] = $fields[$field] ?? $this->encounterReferralForm[$field];
            }
        }
        $this->encounterReferralForm['program_id'] = $fields['program_id'] ?? '';
        $this->encounterReferralOriginalReferences = array_intersect_key($fields, array_flip(['reason_reference', 'supporting_info']));
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

    #[Computed]
    public function encounterReferralRequesterName(): string
    {
        return Auth::user()?->activeDoctorEmployee()?->fullName ?: Auth::user()?->party?->fullName ?: '—';
    }

    public function loadReferralReferenceOptions(string $scope = 'supporting_info'): void
    {
        abort_unless(in_array($scope, ['reason_reference', 'supporting_info'], true), 422);
        $this->encounterReferralReferenceScope = $scope;
        $this->encounterReferralReferencePage = 1;
        $this->encounterReferralReferenceSearch = '';
        $this->encounterReferralReferenceType = '';
        $this->encounterReferralReferenceEpisode = '';
        $this->encounterReferralReferenceHasMore = false;
        $this->encounterReferralEpisodes = [];
        $this->encounterReferralReferenceOptions = $this->lookupReferralReferences();
        $this->encounterReferralEpisodePage = 1;
        $this->loadReferralEpisodeOptions();
    }

    public function loadMoreReferralEpisodeOptions(): void
    {
        if (!$this->encounterReferralEpisodesHasMore) {
            return;
        }
        $this->encounterReferralEpisodePage++;
        $this->loadReferralEpisodeOptions();
    }

    private function loadReferralEpisodeOptions(): void
    {
        $patient = $this->patient();
        $hasAccess = Declaration::accessGrantedTo(Auth::user(), $patient, legalEntity())->exists()
            || Approval::accessGrantedTo(Auth::user(), $patient, legalEntity())->exists();
        $query = $this->referralReferenceQuery('episode', $patient, $hasAccess);
        $records = $query?->orderByDesc('id')->offset(($this->encounterReferralEpisodePage - 1) * 20)->limit(21)->get() ?? collect();
        $this->encounterReferralEpisodesHasMore = $records->count() > 20;
        $this->encounterReferralEpisodes = [...$this->encounterReferralEpisodes, ...$records->take(20)->map(fn ($episode): array => [
            'uuid' => $episode->uuid, 'name' => $episode->name, 'display' => $episode->name,
        ])->all()];
    }

    public function searchReferralReferenceOptions(string $query = '', string $type = '', string $episode = ''): void
    {
        $this->encounterReferralReferenceSearch = mb_substr(trim($query), 0, 100);
        $this->encounterReferralReferenceType = in_array($type, ['condition', 'observation', 'diagnostic_report', 'episode'], true) ? $type : '';
        $this->encounterReferralReferenceEpisode = Str::isUuid($episode) ? $episode : '';
        $this->encounterReferralReferencePage = 1;
        $this->encounterReferralReferenceHasMore = false;
        $this->encounterReferralReferenceOptions = $this->lookupReferralReferences();
    }

    public function loadMoreReferralReferenceOptions(): void
    {
        if (!$this->encounterReferralReferenceHasMore) {
            return;
        }
        $this->encounterReferralReferencePage++;
        $this->encounterReferralReferenceHasMore = false;
        $this->encounterReferralReferenceOptions = [
            ...$this->encounterReferralReferenceOptions, ...$this->lookupReferralReferences(),
        ];
    }

    private function referralReferenceQuery(string $type, Person|Preperson $patient, bool $hasAccess): ?Builder
    {
        $model = ['condition' => Condition::class, 'observation' => Observation::class, 'diagnostic_report' => DiagnosticReport::class, 'episode' => Episode::class][$type] ?? null;
        if ($model === null || !Gate::allows('viewAny', $model)) {
            return null;
        }
        $query = $model === Observation::class
            ? Observation::readableFor($patient, $hasAccess, Auth::user())
            : $model::readableFor($patient, $hasAccess);
        if ($model === Observation::class) {
            $query->notInDraftDiagnosticReport($patient)->whereStatus(\App\Enums\Person\ObservationStatus::VALID);
        }

        if ($model === DiagnosticReport::class) {
            $query->whereStatus(\App\Enums\Person\DiagnosticReportStatus::FINAL);
        }

        return $type === 'episode'
            ? $query->whereNotIn('status', ['draft', 'entered_in_error'])->with('period')
            : $query->notInDraftEncounter($patient)->withAllRelations();
    }

    private function lookupReferralReferences(?array $references = null): array
    {
        $this->resolveEncounterModelForStandalone();
        $patient = $this->patient();
        $hasAccess = Declaration::accessGrantedTo(Auth::user(), $patient, legalEntity())->exists()
            || Approval::accessGrantedTo(Auth::user(), $patient, legalEntity())->exists();
        $options = [];
        $contextByRecord = [];
        $reportByRecord = [];
        $typeLabels = ['condition' => __('conditions.condition_or_diagnosis'), 'observation' => __('observations.label'),
            'diagnostic_report' => __('diagnostic-reports.plural'), 'episode' => __('episodes.plural')];
        $allowedTypes = $this->encounterReferralReferenceScope === 'reason_reference'
            ? ['condition', 'observation'] : ['condition', 'observation', 'diagnostic_report', 'episode'];
        foreach (['condition', 'observation', 'diagnostic_report', 'episode'] as $type) {
            $uuids = $references === null ? null : collect($references)->where('type', $type)->pluck('uuid')->unique()->all();
            if ($references !== null && $uuids === []) {
                continue;
            }
            if ($references === null && (!in_array($type, $allowedTypes, true)
                || ($this->encounterReferralReferenceType !== '' && $this->encounterReferralReferenceType !== $type))) {
                continue;
            }
            $query = $this->referralReferenceQuery($type, $patient, $hasAccess);
            if ($query === null) {
                continue;
            }
            if ($references !== null) {
                $query->whereIn('uuid', $uuids);
            } else {
                $search = $this->encounterReferralReferenceSearch;
                if ($search !== '') {
                    $query->where(function (Builder $searchQuery) use ($type, $search): void {
                        if ($type === 'episode') {
                            $searchQuery->whereLike('name', '%'.$search.'%');
                        } elseif ($type === 'diagnostic_report') {
                            $searchQuery->whereHas('code', fn (Builder $identifier) => $identifier->whereLike('display_value', '%'.$search.'%'));
                        } else {
                            $searchQuery->whereHas('code', fn (Builder $concept) => $concept->whereLike('text', '%'.$search.'%')
                                ->orWhereHas('coding', fn (Builder $coding) => $coding->whereLike('code', '%'.$search.'%')->orWhereLike('display', '%'.$search.'%')));
                        }
                        if (Str::isUuid($search)) {
                            $searchQuery->orWhere('uuid', $search);
                        }
                    });
                }
                $episode = $this->encounterReferralReferenceEpisode;
                if ($episode !== '') {
                    if ($type === 'episode') {
                        $query->where('uuid', $episode);
                    } else {
                        $encounters = Encounter::forPatient($patient)->whereHas('episode', fn (Builder $identifier) => $identifier->whereValue($episode))->selectRaw('uuid::text');
                        $relation = $type === 'diagnostic_report' ? 'encounter' : 'context';
                        $query->where(function (Builder $scoped) use ($relation, $encounters, $patient, $type): void {
                            $scoped->whereHas($relation, fn (Builder $identifier) => $identifier->whereIn('value', $encounters));
                            if ($type === 'observation') {
                                $reports = DiagnosticReport::forPatient($patient)->whereHas('encounter', fn (Builder $identifier) => $identifier->whereIn('value', clone $encounters))->selectRaw('uuid::text');
                                $scoped->orWhereHas('diagnosticReport', fn (Builder $identifier) => $identifier->whereIn('value', $reports));
                            }
                        });
                    }
                }
            }
            $records = $references !== null
                ? $query->orderByDesc('id')->get()
                : $query->orderByDesc('id')->offset(($this->encounterReferralReferencePage - 1) * 20)->limit(21)->get();
            if ($references === null) {
                $this->encounterReferralReferenceHasMore = $this->encounterReferralReferenceHasMore || $records->count() > 20;
                $records = $records->take(20);
            }
            foreach ($records as $record) {
                $concept = $type === 'episode' ? null : $record->code;
                $code = data_get($concept, 'coding.0.code');
                $name = $type === 'episode' ? $record->name : (data_get($concept, 'coding.0.display') ?: data_get($concept, 'display_value') ?: data_get($concept, 'text') ?: $code ?: data_get($concept, 'value') ?: $record->uuid);
                $date = $type === 'episode' ? $record->period?->start : $record->ehealthInsertedAt;
                $date = filled($date) ? convertToAppDateFormat($date) : ($record->created_at?->format('d.m.Y') ?? '—');
                $key = $type.':'.$record->uuid;
                $options[$key] = [
                    'uuid' => $record->uuid, 'type' => $type, 'typeLabel' => $typeLabels[$type],
                    'label' => $code && $code !== $name ? $code.' | '.$name : $name, 'date' => $date,
                    'episode_uuid' => $type === 'episode' ? $record->uuid : '',
                ];
                if ($type !== 'episode') {
                    $contextByRecord[$key] = $type === 'diagnostic_report' ? $record->encounter?->value : $record->context?->value;
                    if ($type === 'observation' && !$contextByRecord[$key]) {
                        $reportByRecord[$key] = $record->diagnosticReport?->value;
                    }
                }
            }
        }
        if ($reportByRecord !== []) {
            $reports = DiagnosticReport::forPatient($patient)->whereIn('uuid', array_filter($reportByRecord))
                ->with('encounter.type.coding')->get()->keyBy('uuid');
            foreach ($reportByRecord as $key => $reportUuid) {
                $contextByRecord[$key] = ($reports[$reportUuid] ?? null)?->encounter?->value;
            }
        }
        $encounters = Encounter::forPatient($patient)->whereIn('uuid', array_filter($contextByRecord))
            ->with('episode.type.coding')->get()->keyBy('uuid');
        foreach ($contextByRecord as $key => $uuid) {
            $options[$key]['episode_uuid'] = ($encounters[$uuid] ?? null)?->episode?->value ?? '';
        }

        return $options;
    }
    public function addReferralReference(string $field, string $type, string $uuid): void
    {
        $this->assertReferralReferenceEditable($field);
        abort_unless(Str::isUuid($uuid), 422);
        $allowedTypes = $field === 'reason_reference' ? ['condition', 'observation'] : ['condition', 'observation', 'diagnostic_report', 'episode'];
        abort_unless(in_array($type, $allowedTypes, true), 404);
        $record = $this->lookupReferralReferences([['type' => $type, 'uuid' => $uuid]])[$type.':'.$uuid] ?? null;
        abort_unless($record !== null && in_array($type, $allowedTypes, true), 404);
        $this->encounterReferralReferenceOptions[$type.':'.$uuid] = $record;
        $references = $this->encounterReferralForm[$field] ?? [];
        if (!collect($references)->contains(fn (array $reference): bool => ($reference['uuid'] ?? null) === $uuid && ($reference['type'] ?? null) === $type)) {
            $references[] = $record;
            $this->encounterReferralForm[$field] = $references;
        }
    }

    public function removeReferralReference(string $field, string $type, string $uuid, ?string $referenceKey = null): void
    {
        $this->assertReferralReferenceEditable($field);
        $this->encounterReferralForm[$field] = array_values(array_filter(
            $this->encounterReferralForm[$field] ?? [],
            fn (array $reference): bool => $referenceKey !== null
                ? md5(json_encode($reference)) !== $referenceKey
                : (($reference['uuid'] ?? null) !== $uuid || ($reference['type'] ?? null) !== $type)
        ));
    }

    private function assertReferralReferenceEditable(string $field): void
    {
        $this->resolveEncounterModelForStandalone();
        abort_unless(in_array($field, ['reason_reference', 'supporting_info'], true), 404);
        abort_if($this->referralReadOnly || $this->prepareReferralForSigning || ($this->encounterReferralSubmission['status'] ?? '') === \App\Enums\JobStatus::PENDING->value, 422);
    }

    protected function validateEncounterReferralReferences(): void
    {
        $fields = ['reason_reference' => ['condition', 'observation'], 'supporting_info' => ['condition', 'observation', 'diagnostic_report', 'episode']];
        $this->validate([
            'encounterReferralForm.reason_reference' => ['array', 'max:100'],
            'encounterReferralForm.supporting_info' => ['array', 'max:100'],
            'encounterReferralForm.reason_reference.*' => ['array'],
            'encounterReferralForm.supporting_info.*' => ['array'],
        ]);
        $requested = [];
        $normalized = [];
        $errors = [];
        foreach ($fields as $field => $types) {
            $originals = collect($this->encounterReferralOriginalReferences[$field] ?? []);
            $normalized[$field] = [];
            foreach ($this->encounterReferralForm[$field] ?? [] as $index => $reference) {
                // Restore the server-loaded reference, including text-only legacy references.
                $original = $originals->first(fn (array $item): bool => $item === $reference
                    || (filled($item['uuid'] ?? null) && filled($item['type'] ?? null)
                        && ($item['uuid'] ?? null) === ($reference['uuid'] ?? null)
                        && ($item['type'] ?? null) === ($reference['type'] ?? null)));
                if ($original !== null) {
                    $normalized[$field][$index] = $original;
                    continue;
                }
                $validator = \Illuminate\Support\Facades\Validator::make($reference, [
                    'uuid' => ['required', 'uuid'], 'type' => ['required', \Illuminate\Validation\Rule::in($types)],
                ]);
                if ($validator->fails()) {
                    $errors['encounterReferralForm.'.$field] = __('encounters.referral_layout.reference_unavailable');
                    continue;
                }
                // Only this pair is used for access checks and for constructing the FHIR reference.
                $requested[] = ['type' => $reference['type'], 'uuid' => $reference['uuid']];
                $normalized[$field][$index] = ['type' => $reference['type'], 'uuid' => $reference['uuid']];
            }
        }
        $available = $requested === [] ? [] : $this->lookupReferralReferences($requested);
        foreach ($normalized as $field => $references) {
            foreach ($references as $reference) {
                if (count($reference) === 2 && in_array($reference, $requested, true)
                    && !isset($available[$reference['type'].':'.$reference['uuid']])) {
                    $errors['encounterReferralForm.'.$field] = __('encounters.referral_layout.reference_unavailable');
                }
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        foreach ($normalized as $field => $references) {
            $this->encounterReferralForm[$field] = array_values($references);
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
        $this->redirectRoute('persons.encounters.referrals.edit', [
            'legalEntity' => $this->legalEntityId,
            'person' => $this->personId,
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
