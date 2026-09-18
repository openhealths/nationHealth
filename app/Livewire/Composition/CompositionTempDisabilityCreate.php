<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Enums\MergeRequest\Status as MergeRequestStatus;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionPregnancyPeriodMode;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Livewire\Composition\Concerns\DrivesCompositionWizard;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MergeRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Services\MedicalEvents\CompositionPregnancyPeriodService;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\Mappers\CompositionMapper;
use Carbon\CarbonImmutable;
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
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->form->subjectUuid = $this->uuid;
        $this->form->isUnidentified = $this->patient() instanceof Preperson;

        // The patient is their own incapacitated person unless a care category says otherwise.
        $this->form->sectionFocusUuid = $this->uuid;
        $this->form->category = CompositionType::TEMP_DISABILITY->defaultCategory()->value;

        $this->applyRelatedConclusion();

        // Opening from an encounter card skips the picker when that encounter is eligible.
        if (filled($this->encounter)) {
            $this->selectEncounter($this->encounter);
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        $options = Fhir::composition()->categoryOptions(CompositionType::TEMP_DISABILITY);

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
     * @return array<string, string>
     */
    #[Computed]
    public function treatmentViolationOptions(): array
    {
        return dictionary()->basics()
            ->byName('COMPOSITION_TREATMENT_VIOLATION')
            ->asCodeDescription()
            ->all();
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
            return $this->pregnancyPeriods()->allowedEndDates(
                $this->form->eventPeriodStart,
                $this->pregnancyPeriodMode()
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

    private function pregnancyPeriods(): CompositionPregnancyPeriodService
    {
        return app(CompositionPregnancyPeriodService::class);
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
        if ($this->selectedCategory()?->hasRestrictedValidityPeriods()) {
            $this->form->eventPeriodEnd = '';
        }

        unset($this->pregnancyPeriodOptions);
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
        $this->resetWizard(['acknowledgedUnidentifiedErln', 'refineFrom', 'continueFrom', 'encounter']);
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
        return $this->form->isUnidentified ? null : $this->form->sectionFocusUuid;
    }

    protected function conclusionType(): CompositionType
    {
        return CompositionType::TEMP_DISABILITY;
    }

    protected function createAbility(): string
    {
        return 'createTempDisability';
    }

    protected function mapperPayload(string $authorEmployeeUuid): array
    {
        return Fhir::composition()->tempDisability($this->form->toMapperData(), $authorEmployeeUuid);
    }

    protected function storagePatient(): Person|Preperson
    {
        return $this->patient();
    }

    protected function detailsRules(): array
    {
        return $this->form->compositionRules(
            $this->categoryOptions,
            array_keys($this->treatmentViolationOptions)
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
            $this->pregnancyPeriods()->assertPeriodAllowed(
                $this->form->eventPeriodStart,
                $this->form->eventPeriodEnd,
                $this->pregnancyPeriodMode()
            );
        }

        // TV 3.8.2.12, 3.8.2.13 — the chain is re-validated against eHealth, because the
        // previous conclusion may have been cancelled since the wizard was opened.
        if (filled($this->form->relatesToTargetUuid)) {
            $this->assertRelatedConclusionUsable(
                (string) $this->form->relatesToTargetUuid,
                $this->form->relatesToCode === CompositionMapper::RELATION_APPENDS
            );
        }
    }

    /**
     * Load the conclusion this one continues or clarifies, refusing an unusable chain.
     *
     * The UUID arrives in the URL, so it is not evidence of anything on its own: it is
     * resolved locally, re-read from eHealth, and checked against the rules for the
     * relation being built.
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

        $fresh = $this->lifecycle()->fetchDetailsFor($previous);

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

            $previousEnd = data_get($fresh, 'event.0.period.end');

            if ($previousEnd !== null
                && CarbonImmutable::parse($this->form->eventPeriodStart)
                    ->lessThanOrEqualTo(CarbonImmutable::parse($previousEnd)->startOfDay())) {
                throw new CompositionGuardException(__('compositions.errors.related_period_overlap'));
            }
        } elseif (!$this->mayClarify($previous)) {
            throw new CompositionGuardException(__('compositions.errors.related_not_clarifiable'));
        }

        return $previous;
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
        if ($previous->subjectUuid === $this->form->subjectUuid) {
            return true;
        }

        $patient = $this->patient();

        if ($patient instanceof Preperson || $previous->prepersonId === null) {
            return false;
        }

        return MergeRequest::query()
            ->whereMasterPersonId($patient->id)
            ->whereMergePersonId($previous->prepersonId)
            ->whereIn('status', [MergeRequestStatus::APPROVED->value, MergeRequestStatus::SIGNED->value])
            ->exists();
    }

    private function applyRelatedConclusion(): void
    {
        $previousUuid = $this->continueFrom ?: $this->refineFrom;

        if ($previousUuid === null) {
            return;
        }

        $previous = Composition::whereUuid($previousUuid)->first();

        if (!$previous?->isTempDisability || empty($previous->data)) {
            Session::flash('error', __('compositions.errors.related_not_found'));

            return;
        }

        // Prefill from the conclusion as eHealth holds it now; the local row is only a
        // projection and may be behind. Falling back to it keeps the wizard usable when
        // the read context is incomplete, and the guard at submit re-reads it anyway.
        $source = $this->lifecycle()->fetchDetailsFor($previous) ?: $previous->data;

        if ($this->continueFrom) {
            $this->form->prefillForContinuation($source);
        } else {
            $this->form->prefillFromPrevious($source);
        }

        $this->form->category = data_get($source, 'category.coding.0.code')
            ?? $previous->category?->value
            ?? $this->form->category;

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
