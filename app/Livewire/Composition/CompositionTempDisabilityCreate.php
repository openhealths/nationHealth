<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Composition\Concerns\DrivesCompositionWizard;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Services\MedicalEvents\Fhir;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
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
            $configurations = EHealth::configuration()->getCompositions([
                'type' => CompositionType::TEMP_DISABILITY->value,
                'category' => CompositionCategory::PREGNANCY->value,
                'is_active' => true,
            ])->getData();
        } catch (EHealthConnectionException | EHealthException $exception) {
            Log::error('Failed to load pregnancy period configuration', ['error' => $exception->getMessage()]);

            return [];
        }

        $start = CarbonImmutable::parse($this->form->eventPeriodStart);
        $needle = filled($this->form->relatesToTargetUuid) ? 'APPENDED' : 'NEW';

        $days = collect($configurations)
            ->filter(static fn (mixed $row): bool => is_array($row)
                && ($needle === 'NEW'
                    ? !str_contains((string) data_get($row, 'name'), 'APPENDED')
                    : str_contains((string) data_get($row, 'name'), 'APPENDED')))
            ->pluck('value')
            ->flatten()
            ->filter(static fn (mixed $value) => is_numeric($value))
            ->map(static fn (mixed $value) => (int) $value);

        if ($days->isEmpty()) {
            $days = collect($configurations)
                ->pluck('value')
                ->flatten()
                ->filter(static fn (mixed $value) => is_numeric($value))
                ->map(static fn (mixed $value) => (int) $value);
        }

        return $days
            ->unique()
            ->sort()
            ->mapWithKeys(static fn (int $count) => [
                $count => $start->addDays($count - 1)->format('Y-m-d'),
            ])
            ->all();
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
        $this->resetWizard(['acknowledgedUnidentifiedErln', 'refineFrom', 'continueFrom']);
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

    private function applyRelatedConclusion(): void
    {
        $previousUuid = $this->continueFrom ?: $this->refineFrom;

        if ($previousUuid === null) {
            return;
        }

        $previous = Composition::whereUuid($previousUuid)->first();

        if (!$previous?->isTempDisability || empty($previous->data)) {
            Session::flash('error', __('patients.composition.errors.related_not_found'));

            return;
        }

        if ($this->continueFrom) {
            $this->form->prefillForContinuation($previous->data);
            $this->form->category = $previous->category?->value ?? $this->form->category;
        } else {
            $this->form->prefillFromPrevious($previous->data);
            $this->form->category = $previous->category?->value ?? $this->form->category;
        }

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
