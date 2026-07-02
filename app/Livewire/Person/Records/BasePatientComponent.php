<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Traits\FormTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

abstract class BasePatientComponent extends Component
{
    use FormTrait;

    /**
     * Person ID (set when the patient is a person).
     *
     * @var int|null
     */
    #[Locked]
    public ?int $personId = null;

    /**
     * Preperson ID (set when the patient is a preperson).
     *
     * @var int|null
     */
    #[Locked]
    public ?int $prepersonId = null;

    /**
     * Request-scoped memoized patient model.
     *
     * @var Person|Preperson|null
     */
    private Person|Preperson|null $patientModel = null;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName;

    public string $verificationStatus;

    /**
     * Whether the list is showing eHealth API search results instead of local observations.
     *
     * @var bool
     */
    public bool $isSearching = false;

    /**
     * Patient declaration number.
     *
     * @var string|null
     */
    public ?string $declarationNumber = null;

    /**
     * Patient UUID.
     *
     * @var string
     */
    #[Locked]
    public string $uuid;

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->loadPatientData();
        $this->initializeComponent();
    }

    /**
     * Resolve the patient model (person or preperson) for the current context.
     *
     * @return Person|Preperson
     */
    protected function patient(): Person|Preperson
    {
        return $this->patientModel ??= ($this->prepersonId !== null
            ? Preperson::findOrFail($this->prepersonId)
            : Person::findOrFail($this->personId));
    }

    /**
     * Resolve a human-readable dictionary label for a coding within a record.
     * Supports both a CodeableConcept (reads the first entry of `coding[]`) and a bare Coding located directly at the given path.
     *
     * @param  array|null  $record  The record holding the coding (e.g. an observation or encounter).
     * @param  string  $path  Dot path to the codeable concept or coding (e.g. 'method', 'code', 'categories.0', 'class').
     * @return string
     */
    #[Computed]
    public function dictionaryLabel(?array $record, string $path): string
    {
        if (empty($record)) {
            return '-';
        }

        $coding = data_get($record, $path . '.coding.0') ?: data_get($record, $path);

        return data_get(
            $this->dictionaries,
            data_get($coding, 'system') . '.' . data_get($coding, 'code'),
            '-'
        );
    }

    /**
     * Get all needed data from DB about patient.
     *
     * @return void
     */
    protected function loadPatientData(): void
    {
        if ($this->prepersonId !== null) {
            $preperson = $this->patient();

            $this->patientFullName = $preperson->fullName;
            $this->uuid = $preperson->uuid;
            $this->verificationStatus = '';

            return;
        }

        $patient = Person::whereId($this->personId)
            ->with(['declarations' => fn (HasMany $declaration) => $declaration->active()->latest()->take(1)])
            ->select(['id', 'uuid', 'first_name', 'last_name', 'second_name', 'verification_status'])
            ->firstOrFail();

        $this->patientFullName = $patient->fullName;
        $this->verificationStatus = $patient->verificationStatus;
        $this->uuid = $patient->uuid;
        $this->declarationNumber = $patient->declarations->first()?->declarationNumber ?? null;
    }

    /**
     * A method that can be overridden in child classes for additional initialization.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
    }
}
