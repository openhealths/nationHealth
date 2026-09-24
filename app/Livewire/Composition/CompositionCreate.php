<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Enums\Composition\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Livewire\Composition\Concerns\DrivesCompositionWizard;
use App\Livewire\Composition\Forms\CompositionForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * Creating a birth conclusion (МВН) — TV 3.8.1.
 *
 * The conclusion is filed against the newborn (a preperson) with the mother as
 * `section.focus`. The wizard can be opened from either card: the missing counterpart
 * is chosen before an encounter of the newborn can be picked.
 */
class CompositionCreate extends BasePatientComponent
{
    use DrivesCompositionWizard;
    use WithFileUploads;

    public CompositionForm $form;

    public string $counterpartQuery = '';

    /** Term applied only after the doctor presses Search / Enter. */
    public string $counterpartSearchTerm = '';

    public string $motherFullName = '';

    public string $newbornFullName = '';

    /** Encounter UUID preselected when opening the wizard from an encounter card. */
    #[Url]
    public ?string $encounter = null;

    public array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'GENDER',
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->form->category = CompositionType::NEWBORN->defaultCategory()->value;

        if ($this->patient() instanceof Preperson) {
            $this->form->prepersonUuid = $this->uuid;
            $this->newbornFullName = $this->patientFullName;
            $this->form->newbornBirthDate = convertToAppDateFormat(
                $this->patient()->birthDate instanceof \DateTimeInterface
                    ? $this->patient()->birthDate->format('Y-m-d')
                    : (string) $this->patient()->birthDate
            );
            $this->form->newbornSex = (string) ($this->patient()->gender?->value ?? '');
        } else {
            $this->form->personUuid = $this->uuid;
            $this->motherFullName = $this->patientFullName;
        }

        if (filled($this->encounter) && !$this->needsNewborn && !$this->needsMother) {
            $this->selectEncounter((string) $this->encounter);
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sexOptions(): array
    {
        // eHealth has no COMPOSITION_NEWBORN_SEX dictionary; NEWBORN_SEX uses GENDER codes.
        $options = dictionary()->basics()
            ->byName('GENDER')
            ->asCodeDescription()
            ->all();

        return $options !== [] ? $options : [
            'MALE' => __('patients.male'),
            'FEMALE' => __('patients.female'),
            'UNKNOWN' => __('compositions.create_newborn.sex_unknown'),
        ];
    }

    /**
     * Local matches for the missing counterpart: the mother when opened from the baby,
     * or the newborn when opened from the mother.
     *
     * @return Collection<int, Person|Preperson>
     */
    #[Computed]
    public function counterpartMatches(): Collection
    {
        $term = trim($this->counterpartSearchTerm);

        if ($term === '') {
            return collect();
        }

        if ($this->needsMother) {
            return Person::query()
                ->with('names')
                ->where(static function ($query) use ($term): void {
                    $query->whereHas(
                        'names',
                        static fn ($names) => $names
                            ->whereLike('last_name', "%{$term}%")
                            ->orWhereLike('first_name', "%{$term}%")
                    )->orWhere('uuid', $term);
                })
                ->limit(10)
                ->get();
        }

        return Preperson::query()
            ->where(static fn ($query) => $query
                ->whereLike('last_name', "%{$term}%")
                ->orWhereLike('first_name', "%{$term}%")
                ->orWhere('uuid', $term))
            ->limit(10)
            ->get();
    }

    /**
     * Run the counterpart search only on explicit action (button or Enter).
     */
    public function searchCounterpart(): void
    {
        $this->counterpartSearchTerm = trim($this->counterpartQuery);
        unset($this->counterpartMatches);
    }

    #[Computed]
    public function needsMother(): bool
    {
        return $this->form->personUuid === '';
    }

    #[Computed]
    public function needsNewborn(): bool
    {
        return $this->form->prepersonUuid === '';
    }

    #[Computed]
    public function hasExistingActiveBirthConclusion(): bool
    {
        if ($this->form->prepersonUuid === '') {
            return false;
        }

        return Composition::query()
            ->whereHas(
                'subject',
                fn ($query) => $query->where('value', $this->form->prepersonUuid)
            )
            ->ofType(CompositionType::NEWBORN)
            ->excludingErrors()
            ->exists();
    }

    public function selectMother(int $personId): void
    {
        $person = Person::with('names')->find($personId);

        if (!$person) {
            return;
        }

        $this->form->personUuid = $person->uuid;
        $this->motherFullName = $person->fullName;
        $this->counterpartQuery = '';
        $this->counterpartSearchTerm = '';
        unset($this->counterpartMatches, $this->needsMother);
        $this->loadAuthMethods();

        // Prefill from the encounter deep link only after both counterparts are known.
        if (filled($this->encounter) && !$this->needsNewborn && !$this->needsMother) {
            $this->selectEncounter((string) $this->encounter);
        }
    }

    public function clearMother(): void
    {
        $this->form->personUuid = '';
        $this->motherFullName = '';
        $this->counterpartQuery = '';
        $this->counterpartSearchTerm = '';
        $this->form->informWithUuid = null;
        $this->authMethods = [];
        $this->form->encounterUuid = '';
        $this->episodeUuid = null;
        $this->step = self::STEP_ENCOUNTER;
        unset($this->needsMother, $this->counterpartMatches);
    }

    public function selectNewborn(int $prepersonId): void
    {
        $preperson = Preperson::find($prepersonId);

        if (!$preperson) {
            return;
        }

        $this->form->prepersonUuid = $preperson->uuid;
        $this->newbornFullName = $preperson->fullName;
        $this->form->newbornBirthDate = convertToAppDateFormat(
            $preperson->birthDate instanceof \DateTimeInterface
                ? $preperson->birthDate->format('Y-m-d')
                : (string) $preperson->birthDate
        );
        $this->form->newbornSex = (string) ($preperson->gender?->value ?? '');
        $this->counterpartQuery = '';
        $this->counterpartSearchTerm = '';
        unset($this->counterpartMatches, $this->needsNewborn, $this->availableEncounters, $this->hasExistingActiveBirthConclusion);

        if (filled($this->encounter) && !$this->needsNewborn && !$this->needsMother) {
            $this->selectEncounter((string) $this->encounter);
        }
    }

    public function clearNewborn(): void
    {
        $this->form->prepersonUuid = '';
        $this->newbornFullName = '';
        $this->form->newbornBirthDate = '';
        $this->form->newbornSex = '';
        $this->form->encounterUuid = '';
        $this->episodeUuid = null;
        $this->step = self::STEP_ENCOUNTER;
        unset($this->needsNewborn, $this->counterpartMatches, $this->availableEncounters, $this->hasExistingActiveBirthConclusion);
    }

    protected function assertCounterpartReadyForEncounter(): bool
    {
        if ($this->needsMother || $this->needsNewborn) {
            Session::flash('error', __('compositions.errors.counterpart_required_before_encounter'));

            return false;
        }

        return true;
    }

    public function restart(): void
    {
        $this->form->resetCompositionFields();
        $this->resetWizard(['counterpartQuery', 'counterpartSearchTerm', 'motherFullName', 'newbornFullName', 'encounter']);
        $this->initializeComponent();
    }

    protected function encounterSubjectUuid(): string
    {
        return $this->form->prepersonUuid;
    }

    protected function authenticationSubjectUuid(): ?string
    {
        return $this->form->personUuid !== '' ? $this->form->personUuid : null;
    }

    public function skipAuthMethod(): void
    {
        // Without a mother there is nobody to inform — send the doctor back to pick one.
        if ($this->needsMother) {
            Session::flash('error', __('compositions.errors.counterpart_required_before_encounter'));
            $this->step = self::STEP_ENCOUNTER;

            return;
        }

        $this->form->informWithUuid = null;
        $this->acknowledgedMissingAuthMethod = true;
        $this->step = self::STEP_DETAILS;
    }

    protected function conclusionType(): CompositionType
    {
        return CompositionType::NEWBORN;
    }

    protected function createAbility(): string
    {
        return 'createNewborn';
    }

    protected function mapperPayload(string $authorEmployeeUuid): array
    {
        return Fhir::composition()->newborn($this->form->toMapperData(), $authorEmployeeUuid);
    }

    protected function storagePatient(): Person|Preperson
    {
        if ($this->patient() instanceof Preperson) {
            return $this->patient();
        }

        return Preperson::whereUuid($this->form->prepersonUuid)->first() ?? $this->patient();
    }

    protected function detailsRules(): array
    {
        return $this->form->compositionRules($this->sexOptions);
    }

    /**
     * TV 3.8.1.3 — a newborn may hold only one birth conclusion that is not in error.
     *
     * The local projection alone cannot answer this (a conclusion issued by another MIS
     * never reaches it), so eHealth is asked as well. A registry that cannot be reached
     * blocks the attempt rather than waving it through: issuing a second conclusion is
     * not something that can be undone by the author afterwards.
     *
     * @throws CompositionGuardException
     */
    protected function assertSubmissionAllowed(): void
    {
        if ($this->hasExistingActiveBirthConclusion) {
            throw new CompositionGuardException(__('compositions.errors.newborn_duplicate'));
        }

        try {
            $existsRemotely = $this->lifecycle()->hasActiveNewbornConclusion($this->form->prepersonUuid);
        } catch (EHealthConnectionException | EHealthException $exception) {
            Log::error('Could not verify whether the newborn already has a birth conclusion', [
                'preperson' => $this->form->prepersonUuid,
                'error' => $exception->getMessage(),
            ]);

            throw new CompositionGuardException(__('compositions.errors.newborn_duplicate_unverifiable'));
        }

        if ($existsRemotely) {
            throw new CompositionGuardException(__('compositions.errors.newborn_duplicate'));
        }
    }

    public function render(): View
    {
        return view('livewire.composition.composition-create');
    }
}
