<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Enums\Person\CompositionType;
use App\Livewire\Composition\Concerns\DrivesCompositionWizard;
use App\Livewire\Composition\Forms\CompositionForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
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

    public string $motherFullName = '';

    public string $newbornFullName = '';

    public array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->form->category = CompositionType::NEWBORN->defaultCategory()->value;

        if ($this->patient() instanceof Preperson) {
            $this->form->prepersonUuid = $this->uuid;
            $this->newbornFullName = $this->patientFullName;
            $this->form->newbornBirthDate = $this->toIsoDate($this->patient()->birthDate);
            $this->form->newbornSex = (string) ($this->patient()->gender?->value ?? '');
        } else {
            $this->form->personUuid = $this->uuid;
            $this->motherFullName = $this->patientFullName;
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sexOptions(): array
    {
        $options = dictionary()->basics()
            ->byName('COMPOSITION_NEWBORN_SEX')
            ->asCodeDescription()
            ->all();

        return $options !== [] ? $options : [
            'MALE' => __('patients.male'),
            'FEMALE' => __('patients.female'),
            'UNKNOWN' => __('patients.composition.create_newborn.sex_unknown'),
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
        $term = trim($this->counterpartQuery);

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
                            ->where('last_name', 'ilike', "%{$term}%")
                            ->orWhere('first_name', 'ilike', "%{$term}%")
                    )->orWhere('uuid', $term);
                })
                ->limit(10)
                ->get();
        }

        return Preperson::query()
            ->where(static fn ($query) => $query
                ->where('last_name', 'ilike', "%{$term}%")
                ->orWhere('first_name', 'ilike', "%{$term}%")
                ->orWhere('uuid', $term))
            ->limit(10)
            ->get();
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
            ->where('subject_uuid', $this->form->prepersonUuid)
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
        unset($this->counterpartMatches, $this->needsMother);
        $this->loadAuthMethods();
    }

    public function selectNewborn(int $prepersonId): void
    {
        $preperson = Preperson::find($prepersonId);

        if (!$preperson) {
            return;
        }

        $this->form->prepersonUuid = $preperson->uuid;
        $this->newbornFullName = $preperson->fullName;
        $this->form->newbornBirthDate = $this->toIsoDate($preperson->birthDate);
        $this->form->newbornSex = (string) ($preperson->gender?->value ?? '');
        $this->counterpartQuery = '';
        unset($this->counterpartMatches, $this->needsNewborn, $this->availableEncounters, $this->hasExistingActiveBirthConclusion);
    }

    public function updatedCounterpartQuery(): void
    {
        unset($this->counterpartMatches);
    }

    public function restart(): void
    {
        $this->form->resetCompositionFields();
        $this->resetWizard(['counterpartQuery', 'motherFullName', 'newbornFullName']);
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

    private function toIsoDate(mixed $value): string
    {
        if (!$value) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }

        try {
            return \Carbon\CarbonImmutable::createFromFormat((string) config('app.date_format'), $raw)
                ->format('Y-m-d');
        } catch (\Throwable) {
            return \Carbon\CarbonImmutable::parse($raw)->format('Y-m-d');
        }
    }

    public function render(): View
    {
        return view('livewire.composition.composition-create');
    }
}
