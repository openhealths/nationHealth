<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Classes\eHealth\EHealth;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionPregnancyPeriodMode;
use App\Enums\Composition\CompositionRelation;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\Composition\TreatmentViolation;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Livewire\Composition\Concerns\DrivesCompositionWizard;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use App\Livewire\Person\Records\BasePatientComponent;
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
class CompositionTempDisabilityCreate extends BasePatientComponent
{
    use DrivesCompositionWizard;
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
        $options = CompositionCategory::optionsFor(CompositionType::TEMP_DISABILITY);
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
     * variables, so the list is filtered by whether this conclusion replaces another.
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
            return $this->pregnancyPeriodMode()->allowedEndDates(
                $this->form->eventPeriodStart,
                $this->pregnancyConfigurations()
            );
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
        return CompositionPregnancyPeriodMode::fromRelation(
            $this->form->relatesToCode,
            $this->form->relatesToTargetUuid
        );
    }

    private function pregnancyConfigurations(): array
    {
        // Legacy EMAL chart parameters have no public MIS provider endpoint. The modern
        // composition_configurations catalog uses different JSON settings, not name/value rows.
        $key = $this->pregnancyPeriodMode()->configurationName();
        $parameter = CompositionConfiguration::from($key);
        $value = $parameter->value();

        return [['name' => $key, 'value' => $value]];
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
            $this->pregnancyPeriodMode()->assertPeriodAllowed(
                $this->form->eventPeriodStart,
                $this->form->eventPeriodEnd,
                $this->pregnancyConfigurations()
            );
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
    private function assertRelatedConclusionUsable(string $previousUuid, bool $isContinuation): Composition
    {
        $previous = Composition::whereUuid($previousUuid)->first();

        if (!$previous?->isTempDisability) {
            throw new CompositionGuardException(__('compositions.errors.related_not_found'));
        }

        $authorUuid = $this->authorEmployeeUuid();

        if ($authorUuid === null || $previous->authorUuid !== $authorUuid) {
            throw new CompositionGuardException(__('compositions.errors.related_not_author'));
        }

        $fresh = $this->fetchComposition($previous);

        if ($fresh === []) {
            throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
        }

        $status = CompositionStatus::fromEHealth(data_get($fresh, 'status'));

        if ($status !== CompositionStatus::FINAL) {
            throw new CompositionGuardException(__('compositions.errors.related_not_final'));
        }

        if (data_get($fresh, 'type.coding.0.code') !== CompositionType::TEMP_DISABILITY->value) {
            throw new CompositionGuardException(__('compositions.errors.related_wrong_type'));
        }

        $previousCategory = (string) data_get($fresh, 'category.coding.0.code');

        if ($previousCategory !== $this->form->category) {
            throw new CompositionGuardException(__('compositions.errors.related_category_mismatch'));
        }

        if ($isContinuation) {
            // A continuation stays with the same patient and must start after the case it
            // extends; a clarification deliberately crosses from a preperson to the
            // identified person, so the subject is allowed to differ there.
            if (data_get($fresh, 'subject.value') !== $this->form->subjectUuid) {
                throw new CompositionGuardException(__('compositions.errors.related_other_patient'));
            }
            if (data_get($fresh, 'section.focus.value') !== $this->form->sectionFocusUuid) {
                throw new CompositionGuardException(__('compositions.errors.care_focus_required'));
            }

            $previousEnd = data_get($fresh, 'event.0.period.end');

            if ($category = CompositionCategory::tryFrom($previousCategory)) {
                if ($category === CompositionCategory::PREGNANCY) {
                    $relation = data_get($fresh, 'relatesTo.code') ?? data_get($fresh, 'relatesTo.0.code');
                    if ($relation === CompositionRelation::APPENDS->value || !is_string($previousEnd)
                        || !CarbonImmutable::parse($this->form->eventPeriodStart)->startOfDay()
                            ->equalTo(CarbonImmutable::parse($previousEnd)->startOfDay()->addDay())) {
                        throw new CompositionGuardException(__('compositions.errors.related_period_overlap'));
                    }
                }
            }

            if ($previousEnd !== null
                && CarbonImmutable::parse($this->form->eventPeriodStart)
                    ->lessThanOrEqualTo(CarbonImmutable::parse($previousEnd)->startOfDay())) {
                throw new CompositionGuardException(__('compositions.errors.related_period_overlap'));
            }
            if (!$this->form->isForceRenew && CompositionConfiguration::TOTAL_SPAN_ENABLED->enabled()) {
                $this->assertContinuationTotalSpanAllowed($previous, $fresh);
            }
        } else {
            if (!$this->mayClarify($previous)) {
                throw new CompositionGuardException(__('compositions.errors.related_not_clarifiable'));
            }
            $this->assertLastConclusionInChain($previous);
        }

        return $previous;
    }

    protected function assertTypeSpecificSigningAllowed(array $document): void
    {
        $relations = $document['relatesTo'] ?? [];
        $relations = array_is_list($relations) ? $relations : [$relations];
        $replacement = collect($relations)->firstWhere('code', CompositionRelation::REPLACES->value);
        if ($replacement === null) {
            return;
        }
        $previous = Composition::whereUuid(data_get($replacement, 'targetIdentifier.value'))->first();
        // TV39 3.8.2.12.1 requires the signed identification pair immediately before KEP as well as at creation.
        if ($previous === null || !$this->mayClarify($previous)) {
            throw new CompositionGuardException(__('compositions.errors.related_not_clarifiable'));
        }
        $this->assertLastConclusionInChain($previous, data_get($document, 'identifier.value'));
    }

    private function assertLastConclusionInChain(Composition $previous, ?string $except = null): void
    {
        // Search every page: an unsynchronized later conclusion must prevent refinement of an earlier link.
        foreach (EHealth::composition()->searchAll(['subject' => $previous->patientUuid, 'type' => CompositionType::TEMP_DISABILITY->value]) as $item) {
            $uuid = data_get($item, 'identifier.value');
            if ($uuid === $previous->uuid || $uuid === $except) {
                continue;
            }
            $episode = data_get($item, 'episodeOfCare.value');
            $encounter = data_get($item, 'encounter.value');
            if (!is_string($episode) || !is_string($encounter)) {
                throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
            }
            $details = EHealth::composition()->getById($previous->patientUuid, $uuid, $episode, $encounter)->validate();
            MedicalEventsRepository::composition()->assertDocumentContext($details, [
                'identifier.value' => $uuid, 'subject.value' => $previous->patientUuid,
                'encounter.value' => $encounter, 'type.coding.0.code' => CompositionType::TEMP_DISABILITY->value,
            ]);
            if (CompositionStatus::fromEHealth($details['status']) !== CompositionStatus::FINAL) {
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

    private function assertContinuationTotalSpanAllowed(Composition $previous, array $fresh): void
    {
        $seen = [];
        $oldestStart = CarbonImmutable::parse(data_get($fresh, 'event.0.period.start'))->startOfDay();
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
            $previous = Composition::whereUuid(data_get($parent, 'targetIdentifier.value'))->first();
            if ($previous === null) {
                throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
            }
            $fresh = $this->fetchComposition($previous);
            if (CompositionStatus::fromEHealth($fresh['status'] ?? null) !== CompositionStatus::FINAL
                || data_get($fresh, 'category.coding.0.code') !== $this->form->category
                || data_get($fresh, 'subject.value') !== $this->form->subjectUuid
                || data_get($fresh, 'section.focus.value') !== $this->form->sectionFocusUuid
                || data_get($fresh, 'author.value') !== $this->authorEmployeeUuid()) {
                throw new CompositionGuardException(__('compositions.errors.related_unreadable'));
            }
        }
        // The case limit includes every earlier extension, rather than only this draft's own period.
        $end = CarbonImmutable::parse($this->form->eventPeriodEnd)->startOfDay();
        if ((int) $oldestStart->diffInDays($end) + 1 > CompositionConfiguration::TOTAL_SPAN->days($this->form->category)) {
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

        $previous = Composition::whereUuid($previousUuid)->first();

        if (!$previous?->isTempDisability
            || !$this->mayClarify($previous) || !in_array($previous->authorUuid, Auth::user()->getCompositionEmployeeUuids(), true)) {
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
}
