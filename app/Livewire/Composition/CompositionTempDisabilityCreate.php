<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Classes\eHealth\EHealth;
use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionPregnancyPeriodMode;
use App\Enums\Composition\CompositionRelation;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\Composition\TreatmentViolation;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Livewire\Composition\CompositionCreateComponent;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * Creating a temporary disability conclusion (МВТН) — TV 3.8.2.
 *
 * The flow is a wizard because eHealth imposes an order that cannot be collapsed into one
 * form: the encounter has to be chosen before anything else (it decides which episode the
 * conclusion is readable through), the conclusion is created unsigned and only becomes a
 * legal document after the author reads it back and signs it, and the creation itself is
 * asynchronous.
 *
 * Nothing is written to the local table until eHealth has assigned the conclusion an id,
 * so abandoning the wizard leaves no orphaned rows behind.
 */
class CompositionTempDisabilityCreate extends CompositionCreateComponent
{
    use WithFileUploads;

    public CompositionTempDisabilityForm $form;

    /** Shown for an unidentified patient before signing (TV 3.8.2.6.1). */
    public bool $acknowledgedUnidentifiedErln = false;

    public string $focusQuery = '';

    public string $focusSearchTerm = '';

    public string $focusFullName = '';

    /** Previous conclusion this one replaces (TV 3.8.2.12). */
    #[Url]
    public ?string $refineFrom = null;

    /** Previous conclusion this one continues (TV 3.8.2.5.4). */
    #[Url]
    public ?string $continueFrom = null;

    /** Encounter UUID preselected when opening the wizard from an encounter card. */
    #[Url]
    public ?string $encounter = null;

    public array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        TreatmentViolation::DICTIONARY,
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->form->subjectUuid = $this->uuid;
        $this->form->isUnidentified = $this->patient() instanceof Preperson;

        // The patient is their own incapacitated person unless a care category says otherwise.
        $this->form->sectionFocusUuid = $this->uuid;
        $this->form->isFocusUnidentified = $this->form->isUnidentified;
        $this->focusFullName = $this->patientFullName;
        $this->form->category = CompositionType::TEMP_DISABILITY->defaultCategory()->value;

        if ($this->resumeAsyncSubmission()) {
            return;
        }

        $this->applyRelatedConclusion();

        // Opening from an encounter card skips the picker when that encounter is eligible.
        if (filled($this->encounter)) {
            $this->selectEncounter((string) $this->encounter);
        }
    }

    /**
     * @return array<string, string>
     */
    public function categoryOptions(): array
    {
        $options = $this->form->categoryOptions();
        try {
            $categories = CompositionConfiguration::DISABILITY_CATEGORIES->codes();
            $position = data_get($this->selectedEncounter, 'authorPosition');
            if (is_string($position)) {
                $categories = array_intersect($categories, CompositionConfiguration::AUTHOR_POSITIONS->codes($position));
            }
            $options = array_intersect_key($options, array_flip($categories));
        } catch (CompositionGuardException) {
            return [];
        }

        // TV 3.8.2.6 limits an unidentified patient to these two categories.
        if ($this->form->isUnidentified) {
            return array_filter(
                $options,
                static fn (string $code) => CompositionCategory::from($code)->isAllowedForPreperson(),
                ARRAY_FILTER_USE_KEY
            );
        }

        return $options;
    }

    /**
     * Validity periods allowed for a pregnancy conclusion (TV 3.8.2.5.4).
     *
     * A new pregnancy conclusion and a continuation use different configuration
     * variables. Refinement and a forced new case select their own applicable sets.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function pregnancyPeriodOptions(): array
    {
        if (!$this->selectedCategory()?->hasRestrictedValidityPeriods() || !$this->form->eventPeriodStart) {
            return [];
        }

        try {
            return $this->allowedPregnancyEndDates($this->form->eventPeriodStart);
        } catch (CompositionGuardException $exception) {
            // Fail closed: without the configuration there is no permitted period to
            // offer, and a free date entry here would only produce a rejected payload.
            Session::flash('error', $exception->getMessage());

            return [];
        }
    }

    /**
     * Which pregnancy period set applies to the conclusion being built (TV 3.8.2.5.4).
     */
    private function pregnancyPeriodMode(): CompositionPregnancyPeriodMode
    {
        if (blank($this->form->relatesToTargetUuid)
            || ($this->form->relatesToCode === CompositionRelation::APPENDS->value && $this->form->isForceRenew)) {
            return CompositionPregnancyPeriodMode::NEW;
        }

        return $this->form->relatesToCode === CompositionRelation::APPENDS->value
            ? CompositionPregnancyPeriodMode::APPENDED : CompositionPregnancyPeriodMode::REPLACEMENT;
    }

    /**
     * Prefill the incapacity period when the details step opens.
     *
     * TV 3.8.2.5.2 allows the end date to equal the start (same calendar day is the
     * shortest valid period). Start defaults to today so the doctor is not staring at
     * empty date fields.
     */
    public function applyDefaultPeriodDates(): void
    {
        $today = now()->format(config('app.date_format'));

        if (blank($this->form->eventPeriodStart)) {
            $this->form->eventPeriodStart = $today;
        }

        if (blank($this->form->eventPeriodEnd)
            && !$this->selectedCategory()?->hasRestrictedValidityPeriods()) {
            $this->form->eventPeriodEnd = $this->form->eventPeriodStart ?: $today;
        }

        unset($this->pregnancyPeriodOptions);
    }

    public function updatedFormCategory(): void
    {
        if (!$this->isCareCategory()) {
            $this->form->sectionFocusUuid = $this->uuid;
            $this->form->isFocusUnidentified = $this->form->isUnidentified;
            $this->focusFullName = $this->patientFullName;
        } else {
            $this->form->sectionFocusUuid = '';
            $this->form->isFocusUnidentified = false;
            $this->focusFullName = '';
        }
        $this->form->informWithUuid = null;
        $this->authMethods = [];
        $this->acknowledgedMissingAuthMethod = false;
        if ($this->form->sectionFocusUuid !== '') {
            $this->loadAuthMethods();
        }

        if ($this->selectedCategory()?->hasRestrictedValidityPeriods()) {
            $this->form->eventPeriodEnd = '';
        }

        unset($this->pregnancyPeriodOptions);
    }

    public function isCareCategory(): bool
    {
        return in_array($this->selectedCategory(), [
            CompositionCategory::CHILD_CARE,
            CompositionCategory::FAMILY_CARE,
            CompositionCategory::PARENTAL_CARE,
        ], true);
    }

    #[Computed]
    public function focusMatches(): array
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            return [];
        }

        return $this->focusSearchTerm === '' ? [] : Repository::person()->searchBySurname($this->focusSearchTerm);
    }

    public function searchFocus(): void
    {
        if (Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_temp_disability_not_allowed'));

            return;
        }
        $this->focusSearchTerm = trim($this->focusQuery);
        unset($this->focusMatches);
    }

    public function selectFocus(int $personId): void
    {
        if (!$this->isCareCategory() || Auth::user()->cannot($this->createAbility(), Composition::class)) {
            Session::flash('error', __('compositions.errors.create_temp_disability_not_allowed'));

            return;
        }
        $person = Repository::person()->getById($personId);
        $this->form->sectionFocusUuid = $person->uuid;
        $this->form->isFocusUnidentified = false;
        $this->focusFullName = $person->fullName;
        $this->focusQuery = '';
        $this->focusSearchTerm = '';
        $this->form->informWithUuid = null;
        $this->acknowledgedMissingAuthMethod = false;
        $this->loadAuthMethods();
        unset($this->focusMatches);
    }

    public function updatedFormEventPeriodStart(): void
    {
        unset($this->pregnancyPeriodOptions);
    }

    /**
     * Start over after an error, keeping the patient context (TV 3.8.2.8.6).
     */
    public function restart(): void
    {
        $this->form->resetCompositionFields();
        $this->resetWizard(['acknowledgedUnidentifiedErln', 'refineFrom', 'continueFrom', 'encounter', 'focusQuery', 'focusSearchTerm', 'focusFullName']);
        $this->initializeComponent();
    }

    /**
     * Whether the unidentified-patient ERLN warning must be acknowledged first (TV 3.8.2.6.1).
     */
    #[Computed]
    public function requiresUnidentifiedErlnWarning(): bool
    {
        return $this->form->isUnidentified
            && $this->selectedCategory() === CompositionCategory::SICKNESS
            && !$this->acknowledgedUnidentifiedErln;
    }

    public function acknowledgeUnidentifiedErln(): void
    {
        $this->acknowledgedUnidentifiedErln = true;
    }

    protected function encounterSubjectUuid(): string
    {
        return $this->uuid;
    }

    protected function authenticationSubjectUuid(): ?string
    {
        return $this->form->isFocusUnidentified ? null : $this->form->sectionFocusUuid;
    }

    protected function conclusionType(): CompositionType
    {
        return CompositionType::TEMP_DISABILITY;
    }

    protected function createAbility(): string
    {
        return 'createTempDisability';
    }

    protected function storagePatient(): Person|Preperson
    {
        return $this->patient();
    }

    protected function detailsRules(): array
    {
        return $this->form->compositionRules(
            $this->categoryOptions(),
            array_keys($this->dictionaries[TreatmentViolation::DICTIONARY] ?? [])
        );
    }

    /**
     * Server-side rules the details step must satisfy before anything is signed.
     *
     * @throws CompositionGuardException
     */
    protected function assertSubmissionAllowed(): void
    {
        if ($this->form->subjectUuid !== $this->uuid || $this->form->isUnidentified !== ($this->patient() instanceof Preperson)) {
            throw new CompositionGuardException(__('compositions.errors.participant_context_mismatch'));
        }
        $category = $this->selectedCategory();

        // Focus is the incapacitated caregiver, while the encounter and storage still belong to the ill subject.
        if ($this->isCareCategory()) {
            if ($this->form->isFocusUnidentified || $this->form->sectionFocusUuid === $this->uuid
                || Repository::person()->findByUuid($this->form->sectionFocusUuid) === null) {
                throw new CompositionGuardException(__('compositions.errors.care_focus_required'));
            }
        } elseif ($this->form->sectionFocusUuid !== $this->uuid
            || $this->form->isFocusUnidentified !== $this->form->isUnidentified) {
            throw new CompositionGuardException(__('compositions.errors.care_focus_required'));
        }

        if ($category === null) {
            throw new CompositionGuardException(__('compositions.errors.category_not_allowed'));
        }

        // TV 3.8.2.6 — an unidentified patient may only be issued these two categories.
        if ($this->form->isUnidentified && !$category->isAllowedForPreperson()) {
            throw new CompositionGuardException(__('compositions.errors.category_not_allowed_preperson'));
        }

        // TV 3.8.2.6.1 — the no-ERLN consequences must be acknowledged, not merely shown.
        if ($this->form->isUnidentified
            && $category === CompositionCategory::SICKNESS
            && !$this->acknowledgedUnidentifiedErln) {
            throw new CompositionGuardException(__('compositions.errors.unidentified_erln_not_acknowledged'));
        }

        // TV 3.8.2.5.4 — a pregnancy period must be one eHealth publishes as allowed.
        if ($category->hasRestrictedValidityPeriods()) {
            $this->assertPregnancyPeriodAllowed($this->form->eventPeriodStart, $this->form->eventPeriodEnd);
        }

        // TV 3.8.2.12, 3.8.2.13 — the chain is re-validated against eHealth, because the
        // previous conclusion may have been cancelled since the wizard was opened.
        if (filled($this->form->relatesToTargetUuid)) {
            $this->assertRelatedConclusionUsable(
                (string) $this->form->relatesToTargetUuid,
                $this->form->relatesToCode === CompositionRelation::APPENDS->value
            );
        }
    }

    /**
     * Load the conclusion this one continues or clarifies, refusing an unusable chain.
     *
     * @throws CompositionGuardException
     */
    private function assertRelatedConclusionUsable(string $previousUuid, bool $isContinuation, ?array $document = null): Composition
    {
        $previous = MedicalEventsRepository::composition()->findByUuid($previousUuid);

        if (!$previous?->isTempDisability) {
            throw new CompositionGuardException(__('compositions.errors.related_not_found'));
        }
        if (Auth::user()->cannot('view', $previous)) {
            throw new CompositionGuardException(__('compositions.errors.view_not_allowed'));
        }

        // Signing uses the stored remote draft, not mutable fields left in the browser form.
        $current = $document === null ? [
            'category' => $this->form->category,
            'subject' => $this->form->subjectUuid,
            'focus' => $this->form->sectionFocusUuid,
            'start' => $this->form->eventPeriodStart,
            'end' => $this->form->eventPeriodEnd,
            'forceRenew' => $this->form->isForceRenew,
        ] : [
            'category' => data_get($document, 'category.coding.0.code'),
            'subject' => data_get($document, 'subject.value'),
            'focus' => data_get($document, 'section.focus.value'),
            'start' => data_get($document, 'event.0.period.start'),
            'end' => data_get($document, 'event.0.period.end'),
            'forceRenew' => (bool) $this->compositionExtensions($document)->get('IS_FORCE_RENEW', false),
        ];

        $authorUuid = $this->authorEmployeeUuid();

        if (!$isContinuation && ($authorUuid === null || $previous->authorUuid !== $authorUuid)) {
            throw new CompositionGuardException(__('compositions.errors.related_not_author'));
        }
        if ($isContinuation && $previous->patientUuid !== $current['subject']) {
            throw new CompositionGuardException(__('compositions.errors.related_other_patient'));
        }
        if (!$isContinuation && !$this->mayClarify($previous)) {
            throw new CompositionGuardException(__('compositions.errors.related_not_clarifiable'));
        }

        $fresh = $this->fetchComposition($previous);

        if ($fresh === []) {
            throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
        }

        $status = $this->compositionStatus(data_get($fresh, 'status'));

        if ($status !== CompositionStatus::FINAL) {
            throw new CompositionGuardException(__('compositions.errors.related_not_final'));
        }

        if (data_get($fresh, 'type.coding.0.code') !== CompositionType::TEMP_DISABILITY->value) {
            throw new CompositionGuardException(__('compositions.errors.related_wrong_type'));
        }

        $previousCategory = (string) data_get($fresh, 'category.coding.0.code');

        if ($previousCategory !== $current['category']) {
            throw new CompositionGuardException(__('compositions.errors.related_category_mismatch'));
        }

        if ($isContinuation) {
            // A continuation stays with the same participants; refinement may cross
            // from a signed merged preperson record to its identified person.
            if (data_get($fresh, 'subject.value') !== $current['subject']) {
                throw new CompositionGuardException(__('compositions.errors.related_other_patient'));
            }
            if (data_get($fresh, 'section.focus.value') !== $current['focus']) {
                throw new CompositionGuardException(__('compositions.errors.care_focus_required'));
            }

            $previousEnd = data_get($fresh, 'event.0.period.end');

            if ($category = CompositionCategory::tryFrom($previousCategory)) {
                if ($category === CompositionCategory::PREGNANCY && !$current['forceRenew']) {
                    $relation = data_get($fresh, 'relatesTo.code') ?? data_get($fresh, 'relatesTo.0.code');
                    if ($relation === CompositionRelation::APPENDS->value || !is_string($previousEnd)
                        || !CarbonImmutable::parse($current['start'])->startOfDay()
                            ->equalTo(CarbonImmutable::parse($previousEnd)->startOfDay()->addDay())) {
                        throw new CompositionGuardException(__('compositions.errors.related_period_overlap'));
                    }
                }
            }

            // Ordinary continuations may overlap; eHealth determines the incapacity case and appends links.
            if (!$current['forceRenew'] && CompositionConfiguration::TOTAL_SPAN_ENABLED->enabled()) {
                $this->assertContinuationTotalSpanAllowed($previous, $fresh, $current);
            }
        } else {
            $this->assertLastConclusionInChain($previous, data_get($document, 'identifier.value'));
        }

        return $previous;
    }

    protected function assertTypeSpecificSigningAllowed(array $document): void
    {
        $relations = $document['relatesTo'] ?? [];
        $relations = array_is_list($relations) ? $relations : [$relations];
        foreach ($relations as $relation) {
            if ($relation['code'] !== CompositionRelation::REPLACES->value) {
                continue;
            }
            // Explicit refinement rechecks the signed identification pair immediately before KEP.
            // Native appends links belong to eHealth's case determination, not a user-selected target.
            $this->assertRelatedConclusionUsable(
                data_get($relation, 'targetIdentifier.value'),
                false,
                $document
            );
        }
    }

    private function assertLastConclusionInChain(Composition $previous, ?string $except = null): void
    {
        // A replacement after identification belongs to the new person, so search both subjects.
        $subjects = array_unique([$previous->patientUuid, $this->patient()->uuid]);
        foreach ($subjects as $subjectUuid) {
            foreach (EHealth::composition()->searchAll(['subject' => $subjectUuid, 'type' => CompositionType::TEMP_DISABILITY->value]) as $item) {
                $uuid = data_get($item, 'identifier.value');
                if ($uuid === $previous->uuid || $uuid === $except) {
                    continue;
                }
                $episode = data_get($item, 'episodeOfCare.value');
                $encounter = data_get($item, 'encounter.value');
                if (!is_string($episode) || !is_string($encounter)) {
                    throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
                }
                $details = $this->fetchCompositionDetails($subjectUuid, $uuid, $episode, $encounter, [
                    'type.coding.0.code' => CompositionType::TEMP_DISABILITY->value,
                ]);
                $local = MedicalEventsRepository::composition()->findByUuid($uuid);
                $pendingSignature = $this->compositionStatus($details['status']) === CompositionStatus::PRELIMINARY
                    && $local !== null && MedicalEventsRepository::compositionOperation()->hasPendingForComposition($local, CompositionAsyncOperation::SIGN);
                if ($this->compositionStatus($details['status']) !== CompositionStatus::FINAL && !$pendingSignature) {
                    continue;
                }
                $relations = $details['relatesTo'] ?? [];
                $relations = array_is_list($relations) ? $relations : [$relations];
                foreach ($relations as $relation) {
                    if (in_array($relation['code'], [CompositionRelation::APPENDS->value, CompositionRelation::REPLACES->value], true)
                        && data_get($relation, 'targetIdentifier.value') === $previous->uuid) {
                        throw new CompositionGuardException(__('compositions.errors.related_not_last'));
                    }
                }
            }
        }
    }

    private function assertContinuationTotalSpanAllowed(Composition $previous, array $fresh, array $current): void
    {
        $seen = [];
        $oldestStart = CarbonImmutable::parse($current['start'])->startOfDay();
        while (true) {
            if (in_array($previous->uuid, $seen, true)) {
                throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
            }
            $seen[] = $previous->uuid;
            $start = CarbonImmutable::parse(data_get($fresh, 'event.0.period.start'))->startOfDay();
            $oldestStart = $start->lt($oldestStart) ? $start : $oldestStart;
            $relations = $fresh['relatesTo'] ?? [];
            $relations = array_is_list($relations) ? $relations : [$relations];
            $parent = collect($relations)->firstWhere('code', CompositionRelation::APPENDS->value);
            if ($parent === null) {
                break;
            }
            $previous = MedicalEventsRepository::composition()->findByUuid(data_get($parent, 'targetIdentifier.value'));
            if ($previous === null) {
                throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
            }
            if (Auth::user()->cannot('view', $previous)) {
                throw new CompositionGuardException(__('compositions.errors.view_not_allowed'));
            }
            $fresh = $this->fetchComposition($previous);
            if ($this->compositionStatus($fresh['status'] ?? null) !== CompositionStatus::FINAL
                || data_get($fresh, 'category.coding.0.code') !== $current['category']
                || data_get($fresh, 'subject.value') !== $current['subject']
                || data_get($fresh, 'section.focus.value') !== $current['focus']) {
                throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
            }
        }
        // The case limit includes every earlier extension, rather than only this draft's own period.
        $end = CarbonImmutable::parse($current['end'])->startOfDay();
        if ((int) $oldestStart->diffInDays($end) + 1 > CompositionConfiguration::TOTAL_SPAN->days($current['category'])) {
            throw new CompositionGuardException(__('compositions.errors.period_too_long'));
        }
    }

    /**
     * Whether this patient may supersede the given conclusion (TV 3.8.2.12).
     *
     * Either the conclusion already belongs to them, or it belongs to an unidentified
     * record that has since been merged into them — which is exactly the case the
     * post-identification clarification exists for.
     */
    private function mayClarify(Composition $previous): bool
    {
        $patient = $this->patient();
        if ($previous->subjectUuid === $patient->uuid) {
            return true;
        }

        if ($patient instanceof Preperson || $previous->prepersonId === null) {
            return false;
        }

        return MedicalEventsRepository::composition()->hasSignedIdentification($patient, $previous->prepersonId);
    }

    private function applyRelatedConclusion(): void
    {
        $previousUuid = $this->continueFrom ?: $this->refineFrom;

        if ($previousUuid === null) {
            return;
        }

        $previous = MedicalEventsRepository::composition()->findByUuid($previousUuid);

        if (!$previous?->isTempDisability || Auth::user()->cannot('view', $previous)
            || ($this->continueFrom && $previous->patientUuid !== $this->uuid)
            || (!$this->continueFrom && (!$this->mayClarify($previous)
                || !in_array($previous->authorUuid, Auth::user()->getCompositionEmployeeUuids(), true)))) {
            Session::flash('error', __('compositions.errors.related_not_found'));

            return;
        }

        // Prefill locally; submission re-reads and validates the current eHealth record.
        $source = $previous->toDetail();

        if ($this->continueFrom) {
            $this->form->prefillForContinuation($source);
        } else {
            $this->form->prefillFromPrevious($source);
        }

        $this->form->category = data_get($source, 'category.coding.0.code')
            ?? ($previous->category?->value ?? $this->form->category);

        unset($this->pregnancyPeriodOptions);
    }

    private function selectedCategory(): ?CompositionCategory
    {
        return CompositionCategory::tryFrom($this->form->category);
    }

    public function render(): View
    {
        return view('livewire.composition.composition-temp-disability-create');
    }

    /** Build the unsigned request only after the component has validated its context and form. */
    protected function buildCompositionPayload(string $authorEmployeeUuid): array
    {
        $subjectResource = $this->form->isUnidentified ? 'preperson' : 'person';

        $payload = $this->base([
            'type' => CompositionType::TEMP_DISABILITY,
            'category' => $this->form->category,
            'subjectUuid' => $this->form->subjectUuid,
            'subjectResource' => $subjectResource,
            'encounterUuid' => $this->form->encounterUuid,
            'authorEmployeeUuid' => $authorEmployeeUuid,
            'focusUuid' => $this->form->sectionFocusUuid,
            'focusResource' => $this->form->isFocusUnidentified ? 'preperson' : 'person',
            'periodStart' => $this->startOfDay($this->form->eventPeriodStart),
            'periodEnd' => $this->endOfDay($this->form->eventPeriodEnd),
        ]);

        $extensions = $this->informWith($this->form->informWithUuid ?? null);

        foreach ([
            'IS_ACCIDENT' => $this->form->isAccident ?? false,
            'IS_INTOXICATED' => $this->form->isIntoxicated ?? false,
            'IS_FOREIGN_TREATMENT' => $this->form->isForeignTreatment ?? false,
            'IS_FORCE_RENEW' => $this->form->isForceRenew ?? false,
        ] as $code => $isSet) {
            if ($isSet) {
                $extensions[] = ['valueCode' => $code, 'valueBoolean' => true];
            }
        }

        if (!empty($this->form->treatmentViolation)) {
            $extensions[] = [
                'valueCode' => 'TREATMENT_VIOLATION',
                'valueString' => $this->form->treatmentViolation,
            ];

            if (!empty($this->form->treatmentViolationDate)) {
                $extensions[] = [
                    'valueCode' => 'TREATMENT_VIOLATION_DATE',
                    'valueDate' => $this->date($this->form->treatmentViolationDate),
                ];
            }
        }

        $payload['extension'] = $extensions;

        // Create Composition accepts an explicit replacement. eHealth adds appends itself when
        // determining the incapacity case; a continuation target is only a form-prefill context.
        if (!empty($this->form->relatesToTargetUuid) && $this->form->relatesToCode === CompositionRelation::REPLACES->value) {
            $payload['relatesTo'] = [
                'code' => CompositionRelation::REPLACES->value,
                'targetIdentifier' => $this->resourceIdentifier('composition', $this->form->relatesToTargetUuid),
            ];
        }

        return $payload;
    }

    protected function allowedPregnancyDays(): array
    {
        $parameter = CompositionConfiguration::from($this->pregnancyPeriodMode()->configurationName());
        // Read the exact mode's validated EMAL value, including a forced new pregnancy case.
        $days = collect($parameter->value())->flatten()->filter(static fn (mixed $value): bool => is_int($value) && $value > 0)
            ->unique()->sort()->values()->all();
        if ($days === []) {
            throw new CompositionGuardException(__('compositions.errors.pregnancy_periods_unavailable'));
        }

        return $days;
    }

    protected function allowedPregnancyEndDates(string $start): array
    {
        $from = CarbonImmutable::parse($start);

        $options = [];

        foreach ($this->allowedPregnancyDays() as $days) {
            $options[$days] = $from->addDays($days - 1)->format(config('app.date_format'));
        }

        return $options;
    }

    protected function assertPregnancyPeriodAllowed(string $start, string $end): void
    {
        $allowed = $this->allowedPregnancyEndDates($start);
        $candidate = CarbonImmutable::parse($end)->format(config('app.date_format'));

        if (!in_array($candidate, $allowed, true)) {
            throw new CompositionGuardException(
                __('compositions.errors.pregnancy_period_not_allowed', [
                    'periods' => implode(', ', $allowed),
                ])
            );
        }
    }
}
