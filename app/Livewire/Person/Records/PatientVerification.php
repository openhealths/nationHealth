<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\VerificationSource;
use App\Enums\Person\VerificationStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Forms\PersonVerificationForm as Form;
use App\Models\Relations\PersonVerificationDetail;
use App\Repositories\Repository;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;

class PatientVerification extends BasePatientComponent
{
    use WithFileUploads;

    public Form $form;

    /**
     * Whether the user is asked to sign the death verification decision.
     *
     * @var bool
     */
    public bool $showSignatureModal = false;

    /**
     * Registries the person verification result is shown for, in display order.
     *
     * @var array
     */
    private const array DISPLAYED_SOURCES = [
        VerificationSource::DRFO->value,
        VerificationSource::DRACS_DEATH->value,
        VerificationSource::DRACS_BIRTH->value,
        VerificationSource::DMS_PASSPORT->value,
        VerificationSource::UNZR->value,
        VerificationSource::NHS->value
    ];

    /**
     * Dictionaries used to resolve the verification reason labels.
     *
     * @var array
     */
    protected array $dictionaryNames = ['PERSON_VERIFICATION_STATUS_REASONS'];

    protected function initializeComponent(): void
    {
        $this->getDictionary();
    }

    /**
     * Result of the person data verification, one row per registry, in the order the registries are declared.
     *
     * @return Collection
     */
    #[Computed]
    public function verificationDetails(): Collection
    {
        $sourceOrder = array_flip(self::DISPLAYED_SOURCES);

        return PersonVerificationDetail::wherePersonId($this->personId)
            ->whereIn('source', self::DISPLAYED_SOURCES)
            ->get()
            ->sortBy(static fn (PersonVerificationDetail $detail): int => $sourceOrder[$detail->source->value])
            ->values();
    }

    /**
     * Get the person verification details from eHealth and store them locally.
     *
     * @return void
     */
    public function getVerificationStatus(): void
    {
        if (Auth::user()->cannot('view', PersonVerificationDetail::class)) {
            Session::flash('error', __('patients.policy.verification_details'));

            return;
        }

        try {
            $response = EHealth::person()->getPersonVerificationDetails($this->uuid);
            $validated = $response->validate();

            try {
                Repository::person()->updateVerificationStatusById(
                    $this->personId,
                    $validated['verification_status']
                );
                Repository::person()->syncVerificationDetails($this->personId, $validated['details']);
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Error when updating person verification status');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting person verification details');

            return;
        }

        Session::flash('success', __('patients.sync_success'));
    }

    /**
     * Take the death verification decision made in the modal and ask the user to sign it.
     *
     * @return bool Whether the decision is ready to be signed, so that the modal knows if it can be closed.
     */
    public function confirmDeathVerification(): bool
    {
        if (Auth::user()->cannot('update', [PersonVerificationDetail::class, $this->patient()])) {
            Session::flash('error', __('patients.policy.verification_update'));

            return false;
        }

        $this->form->validate($this->form->rulesForDracsDeath());

        $this->showSignatureModal = true;

        return true;
    }

    /**
     * Sign the death verification decision and send it to eHealth.
     *
     * @return void
     */
    public function signDeathVerification(): void
    {
        if (Auth::user()->cannot('update', [PersonVerificationDetail::class, $this->patient()])) {
            Session::flash('error', __('patients.policy.verification_update'));

            return;
        }

        $dracsDeathRules = $this->form->rulesForDracsDeath();
        $validated = $this->form->validate([...$dracsDeathRules, ...$this->form->signingRules()]);
        $validated['deathDate'] = convertToYmd($validated['deathDate']);

        $dracsDeath = [
            'dracs_death' => removeEmptyKeys(
                Arr::toSnakeCase(Arr::only($validated, array_keys($dracsDeathRules)))
            )
        ];

        try {
            $signedContent = (new CipherRequest())->signData(
                $dracsDeath,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing data with Cipher');

            return;
        }

        try {
            $response = EHealth::person()->updateVerificationStatus(
                $this->uuid,
                ['signed_content' => $signedContent->getBase64Data()]
            );
            $validatedResponse = $response->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating person verification status');

            return;
        }

        try {
            Repository::person()->updateVerificationStatusById(
                $this->personId,
                $validatedResponse['verification_status']
            );
            Repository::person()->syncVerificationDetails($this->personId, $validatedResponse['details']);
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error when storing updated person verification status');

            return;
        }

        $this->form->resetSigningFields();
        $this->showSignatureModal = false;

        Session::flash('success', __('patients.messages.verification_status_updated'));
    }

    /**
     * Whether any registry has refuted the person data.
     *
     * @return bool
     */
    #[Computed]
    public function hasVerificationIssues(): bool
    {
        return $this->verificationDetails->contains(
            static fn (PersonVerificationDetail $detail): bool
                => $detail->verificationStatus === VerificationStatus::NOT_VERIFIED
        );
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-verification')->with('person', $this->patient());
    }
}
