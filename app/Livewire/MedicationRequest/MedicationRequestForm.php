<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class MedicationRequestForm extends Component
{
    use WithFileUploads;

    #[Locked]
    #[Locked]
    public LegalEntity $legalEntity;

    public string $patientId = '';
    public string $medicalProgram = '';
    public string $dosageInstruction = '';
    public string $duration = '';

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
        'dosageInstruction' => 'required|string',
        'duration' => 'required|numeric|min:1',
    ];

    public function preQualify(MedicationRequestLifecycleService $service): void
    {
        $this->validate();

        try {
            $service->preQualify([
                'person_id' => $this->patientId,
                'medical_program_id' => $this->medicalProgram,
                'programs' => [
                    ['id' => $this->medicalProgram],
                ],
            ]);

            $this->statusMessage = __('care-plan.prequalify_passed');
        } catch (EHealthValidationException $e) {
            $this->failWith($e->getFormattedMessage());
        } catch (Exception $e) {
            $this->failWith($e->getMessage());
        }
    }

    public function createDraft(MedicationRequestLifecycleService $service): void
    {
        $this->validate();

        try {
            $response = $service->createDraft([
                'person_id' => $this->patientId,
                'medical_program_id' => $this->medicalProgram,
                'dosage_instruction' => $this->dosageInstruction,
                'dispense_request' => [
                    'expected_supply_duration' => [
                        'value' => (int) $this->duration,
                        'system' => 'http://unitsofmeasure.org',
                        'code' => 'd',
                    ],
                ],
            ]);
        } catch (EHealthValidationException $e) {
            $this->failWith($e->getFormattedMessage());

            return;
        } catch (Exception $e) {
            $this->failWith($e->getMessage());

            return;
        }

        $draftId = $response['id'] ?? ($response['medication_request_request']['id'] ?? null);

        if (!is_string($draftId) || $draftId === '') {
            Log::channel('e_health_errors')->error('ePrescription draft created without an identifier', [
                'person_id' => $this->patientId,
            ]);
            $this->failWith(__('care-plan.draft_missing_identifier'));

            return;
        }

        $this->isDraftCreated = true;
        $this->draftId = $draftId;
        $this->draftContent = $response['medication_request_request'] ?? $response;

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

    public function sign(MedicationRequestLifecycleService $service): void
    {
        if (!$this->isDraftCreated || $this->draftId === null) {
            $this->failWith(__('care-plan.draft_required_before_signing'));

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

            $service->sign($this->draftId, [
                'signed_medication_request_request' => $signedContent,
                'signed_content_encoding' => 'base64',
            ]);
        } catch (EHealthValidationException $e) {
            $this->failWith($e->getFormattedMessage());

            return;
        } catch (Exception $e) {
            $this->failWith($e->getMessage());

            return;
        } finally {
            $this->resetSigningFields();
        }

        $this->showSignatureModal = false;
        $this->statusMessage = __('care-plan.prescription_signed');
        session()->flash('success', $this->statusMessage);
        $this->dispatch('medication-request-created');
    }

    public function render()
    {
        return view('livewire.medication-request.medication-request-form');
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
