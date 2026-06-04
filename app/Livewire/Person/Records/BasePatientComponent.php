<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Traits\FormTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

abstract class BasePatientComponent extends Component
{
    use FormTrait;

    /**
     * Person ID.
     *
     * @var int
     */
    #[Locked]
    public int $personId;

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

    public function mount(LegalEntity $legalEntity, int $personId): void
    {
        $this->personId = $personId;
        $this->loadPatientData();
        $this->initializeComponent();
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
