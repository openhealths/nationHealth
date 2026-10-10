<?php

declare(strict_types=1);

namespace App\Livewire\DeviceRequest;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Classes\eHealth\Api\DeviceRequest as DeviceRequestApi;
use App\Dto\DeviceRequest\EhealthDraft;
use App\Dto\DeviceRequest\EhealthDraftPrequalify;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

class DeviceRequestForm extends Component
{
    use WithFileUploads;

    public LegalEntity $legalEntity;

    public string $patientId = '';
    public string $medicalProgram = '';
    public string $deviceType = '';
    public string $quantity = '';

    public bool $isDraftCreated = false;
    public ?string $draftId = null;
    public ?string $statusMessage = null;
    public bool $showSignatureModal = false;

    /**
     * Draft content returned by eHealth. This is what gets signed with the KEP,
     * so it must never be reconstructed locally.
     *
     * @var array<string, mixed>
     */
    public array $draftContent = [];

    /** @var array<string, mixed> */
    public array $form = [
        'knedp' => '',
        'keyContainerUpload' => null,
        'keyContainerFileName' => '',
        'password' => '',
    ];

    /** @var array<string, string> */
    protected array $rules = [
        'patientId' => 'required|string',
        'medicalProgram' => 'required|string',
        'deviceType' => 'required|string',
        'quantity' => 'required|numeric|min:1',
    ];

    public function preQualify(): void
    {
        $this->validate();

        try {
            $payload = app(ObjectMapperInterface::class)->map($this, EhealthDraftPrequalify::class)->toArray();
            app(DeviceRequestApi::class)->prequalifyAndValidate($payload);

            $this->statusMessage = __('care-plan.prequalify_passed');
        } catch (EHealthValidationException $e) {
            $this->failWith($e->getFormattedMessage());
        } catch (Exception $e) {
            $this->failWith($e->getMessage());
        }
    }

    public function createDraft(): void
    {
        $this->validate();

        try {
            $payload = app(ObjectMapperInterface::class)->map($this, EhealthDraft::class)->toArray();
            $created = app(DeviceRequestApi::class)->createAndResolve($payload);
        } catch (EHealthValidationException $e) {
            $this->failWith($e->getFormattedMessage());

            return;
        } catch (Exception $e) {
            $this->failWith($e->getMessage());

            return;
        }

        $draftId = $created->uuid();
        $draftContent = $created->document();

        if (!is_string($draftId) || $draftId === '') {
            Log::channel('e_health_errors')->error('Device request draft created without an identifier', [
                'person_id' => $this->patientId,
            ]);
            $this->failWith(__('care-plan.draft_missing_identifier'));

            return;
        }

        if ($draftContent === []) {
            $this->failWith(__('care-plan.draft_missing_document'));

            return;
        }

        $this->isDraftCreated = true;
        $this->draftId = $draftId;
        $this->draftContent = $draftContent;

        $this->statusMessage = __('care-plan.draft_created_awaiting_signature', ['id' => $draftId]);
    }

    public function openSignatureModal(): void
    {
        if (!$this->isDraftCreated || $this->draftId === null) {
            $this->failWith(__('care-plan.draft_required_before_signing'));

            return;
        }

        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        if (!$this->isDraftCreated || $this->draftId === null) {
            $this->failWith(__('care-plan.draft_required_before_signing'));
            $this->showSignatureModal = false;

            return;
        }

        $this->validate([
            'form.knedp' => 'required|string',
            'form.keyContainerUpload' => 'required|file|max:1024',
            'form.password' => 'required|string',
        ]);

        try {
            $signedContent = signatureService()->signData(
                $this->draftContent,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                (string) Auth::user()?->party?->taxId
            );

            app(DeviceRequestApi::class)->signAndResolve($this->draftId, [
                'signed_device_request_request' => $signedContent,
                'signed_content_encoding' => 'base64',
            ]);
        } catch (EHealthValidationException $e) {
            $this->failWith($e->getFormattedMessage());
            $this->showSignatureModal = false;

            return;
        } catch (Exception $e) {
            $this->failWith($e->getMessage());
            $this->showSignatureModal = false;

            return;
        } finally {
            $this->resetSigningFields();
        }

        $this->showSignatureModal = false;
        $this->statusMessage = __('care-plan.device_request_signed');
        session()->flash('success', $this->statusMessage);
        $this->dispatch('device-request-created');
    }

    public function render()
    {
        return view('livewire.device-request.device-request-form');
    }

    private function resetSigningFields(): void
    {
        $this->form['password'] = '';
        $this->form['keyContainerUpload'] = null;
        $this->form['keyContainerFileName'] = '';
    }

    private function failWith(string $message): void
    {
        $this->statusMessage = $message;
        session()->flash('error', $message);
    }
}
