<?php

declare(strict_types=1);

namespace App\Livewire\Specimen;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\Specimen\EhealthCancellation;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Specimen\Forms\SpecimenCancellationForm;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * Marking a specimen as entered in error with a signed package. Embedded once into a page, which opens it for
 * a particular specimen of the patient.
 */
class SpecimenCancellation extends Component
{
    use FormTrait;
    use WithFileUploads;

    public SpecimenCancellationForm $form;

    #[Locked]
    public string $patientId;

    #[Locked]
    public string $specimenId = '';

    public bool $showCancellationModal = false;

    public bool $showSignatureModal = false;

    protected array $dictionaryNames = ['specimen_cancel_reasons'];

    /**
     * Component mount.
     *
     * @param  string  $patientId
     * @return void
     */
    public function mount(string $patientId): void
    {
        $this->patientId = $patientId;
        $this->getDictionary();
    }

    /**
     * Ask for the reason the given specimen of the patient is being marked as entered in error.
     *
     * @param  string  $specimenId
     * @return void
     */
    public function open(string $specimenId): void
    {
        $this->resetCancellationState();

        $this->specimenId = $specimenId;
        $this->showCancellationModal = true;
    }

    /**
     * Hand the collected reason over to the signing step.
     *
     * @return void
     */
    public function proceedToSignature(): void
    {
        $this->form->validate($this->form->cancellationRules());

        $this->showCancellationModal = false;
        $this->showSignatureModal = true;
    }

    /**
     * Sign the specimen as it is stored in eHealth, marked as entered in error, and send it.
     *
     * @return void
     */
    public function cancel(): void
    {
        try {
            $validated = $this->form->validate([
                ...$this->form->cancellationRules(),
                ...$this->form->signingRules()
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::specimen()->getDetails($this->patientId, $this->specimenId);
            $specimen = Arr::toCamelCase($response->validate());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the specimen for cancellation');

            return;
        }

        if (Auth::user()->cannot('cancel', [Specimen::class, $specimen])) {
            $this->resetCancellationState();
            Session::flash('error', __('specimens.policy.cancel'));

            return;
        }

        try {
            $signedContent = new CipherRequest()->signData(
                app(ObjectMapperInterface::class)->map($this->form, new EhealthCancellation($response->getData()))->toArray(),
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error while signing specimen cancellation package');

            return;
        } finally {
            $this->form->resetSigningFields();
        }

        try {
            EHealth::specimen()->cancel($this->patientId, $this->specimenId, [
                'signed_data' => $signedContent->getBase64Data(),
                'signed_data_encoding' => 'base64'
            ]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while sending specimen cancellation package');

            return;
        }

        $this->resetCancellationState();
        Session::flash('success', __('specimens.messages.cancel_request_sent'));
    }

    /**
     * Close both steps of the cancellation and forget everything collected along the way.
     *
     * @return void
     */
    private function resetCancellationState(): void
    {
        $this->showCancellationModal = false;
        $this->showSignatureModal = false;
        $this->specimenId = '';
        $this->form->reset('cancellationReason');
        $this->form->resetSigningFields();

        $this->resetErrorBag();
    }

    /**
     * Render the component.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.specimen.specimen-cancellation');
    }
}
