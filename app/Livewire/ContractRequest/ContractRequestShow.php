<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Classes\eHealth\EHealth;
use App\Contracts\SignatureProvider;
use App\Enums\Contract\IdForm;
use App\Enums\Contract\Type;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\Signature\SignatureException;
use App\Livewire\Contract\Forms\ContractRequestSigningForm as SigningForm;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class ContractRequestShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $contractRequestId;

    protected ContractRequest $contractRequest;

    public function boot(): void
    {
        if (isset($this->contractRequestId)) {
            $this->contractRequest = ContractRequest::query()->forLegalEntity(legalEntity())->findOrFail($this->contractRequestId);
            abort_if(Auth::user()->cannot('view', $this->contractRequest), 403);
        }
    }
    public SigningForm $form;
    public array $contractData = [];
    public array $medicalProgramNames = [];
    public bool $showSignatureModal = false;
    public ?string $pendingAction = null;

    public function mount(LegalEntity $legalEntity, ContractRequest $contractRequest): void
    {
        abort_if(Auth::user()->cannot('view', $contractRequest), 403);
        $this->contractRequestId = $contractRequest->id;
        $this->contractRequest = $contractRequest;
        $this->medicalProgramNames = $this->resolveMedicalProgramNames();

        if ($this->contractRequest->uuid && $this->contractRequest->type) {
            $this->syncDetailsFromEHealth();
        }

        $this->contractData = $this->normalizeContractData($this->contractRequest->data ?? []);
    }

    public function canApproveContractRequest(): bool
    {
        return auth()->user()->can('approve', $this->contractRequest);
    }

    public function canSignContractRequest(): bool
    {
        return auth()->user()->can('sign', $this->contractRequest);
    }

    public function openApproveModal(): void
    {
        if (Auth::user()->cannot('approve', $this->contractRequest)) {
            Session::flash('error', __('contracts.policy.approve_denied'));

            return;
        }

        $this->pendingAction = 'approve';
        $this->showSignatureModal = true;
    }

    public function openSignModal(): void
    {
        if (Auth::user()->cannot('sign', $this->contractRequest)) {
            Session::flash('error', __('contracts.policy.sign_denied'));

            return;
        }

        $this->pendingAction = 'sign';
        $this->showSignatureModal = true;
    }

    public function submitSignedAction(): void
    {
        match ($this->pendingAction) {
            'approve' => $this->approve(),
            'sign' => $this->sign(),
            default => null,
        };
    }

    public function approve(): void
    {
        if (Auth::user()->cannot('approve', $this->contractRequest)) {
            Session::flash('error', __('contracts.policy.approve_denied'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        try {
            if (!$this->syncDetailsFromEHealth() || !$this->canApproveContractRequest()) {
                return;
            }
            $dataToSign = $this->buildJsonDataToSign();
            $signedContent = signatureService()->signData(
                $dataToSign,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );

            $response = EHealth::contractRequest()->approveMsp(
                $this->contractRequest->uuid,
                strtolower((string) $this->contractRequest->type),
                [
                    'signed_content' => $signedContent,
                    'signed_content_encoding' => 'base64',
                ]
            );

            $this->persistEHealthResponse($response->validate());
            $this->showSignatureModal = false;
            $this->pendingAction = null;
            Session::flash('success', __('contracts.approve_success'));
        } catch (SignatureException $exception) {
            $exception->handle('Error when signing contract request approval');
        } catch (Throwable $exception) {
            $this->handleActionError($exception, 'approve');
        }
    }

    public function sign(): void
    {
        if (Auth::user()->cannot('sign', $this->contractRequest)) {
            Session::flash('error', __('contracts.policy.sign_denied'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        try {
            $contractType = strtolower((string) $this->contractRequest->type);
            $signedContent = $this->signPartiallySignedContent(
                $validated,
                $contractType
            );

            $response = EHealth::contractRequest()->signMsp(
                $this->contractRequest->uuid,
                $contractType,
                [
                    'signed_content' => $signedContent,
                    'signed_content_encoding' => 'base64',
                ]
            );

            $this->persistEHealthResponse($response->validate());
            $this->showSignatureModal = false;
            $this->pendingAction = null;
            Session::flash('success', __('contracts.sign_success'));
        } catch (SignatureException $exception) {
            $exception->handle('Error when signing contract request');
        } catch (Throwable $exception) {
            $this->handleActionError($exception, 'sign');
        }
    }

    /**
     * Builds signed content for sign_msp: co-sign NHS content when available, else sign JSON.
     *
     * At NHS_SIGNED, eHealth usually returns partially signed PKCS7 via getSignedContent().
     * That blob must be co-signed with {@see SignatureProvider::signBase64Data()}.
     * If the endpoint is unavailable (404), falls back to signing fresh JSON from local data.
     *
     * @param  array<string, mixed>  $validated
     */
    private function signPartiallySignedContent(array $validated, string $contractType): string
    {
        try {
            $signedContentResponse = EHealth::contractRequest()->getSignedContent(
                $this->contractRequest->uuid,
                $contractType
            );
            $signedContentData = $signedContentResponse->validate();
            $base64Payload = $signedContentData['content'] ?? $signedContentData['signed_content'] ?? null;

            if (is_string($base64Payload) && $base64Payload !== '') {
                return signatureService()->signBase64Data(
                    $base64Payload,
                    $validated['knedp'],
                    $validated['keyContainerUpload'],
                    $validated['password'],
                    Auth::user()->party->taxId
                );
            }
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            Log::warning('Partially signed contract request content is unavailable, falling back to JSON signing.', [
                'contract_request_uuid' => $this->contractRequest->uuid,
            ]);
        }

        if (!$this->syncDetailsFromEHealth()) {
            throw new RuntimeException(__('contracts.details_unavailable'));
        }

        return signatureService()->signData(
            $this->buildJsonDataToSign(),
            $validated['knedp'],
            $validated['keyContainerUpload'],
            $validated['password'],
            Auth::user()->party->taxId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJsonDataToSign(): array
    {
        $data = $this->normalizeContractData($this->contractRequest->fresh()->data ?? []);

        unset($data['urgent'], $data['uuid'], $data['type'], $data['sync_status']);

        if (!empty($this->contractRequest->printoutContent)) {
            $data['printout_content'] = $this->contractRequest->printoutContent;
        }

        if (empty($data)) {
            throw new RuntimeException(__('contracts.sign_data_unavailable'));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function persistEHealthResponse(array $responseData): void
    {
        Repository::contractRequest()->saveFromEHealth(
            $responseData,
            strtoupper((string) $this->contractRequest->type)
        );

        $this->contractRequest->refresh();
        $this->contractData = $this->normalizeContractData($this->contractRequest->data ?? []);
    }

    private function handleActionError(Throwable $exception, string $action): void
    {
        if ($exception instanceof EHealthResponseException) {
            Log::error("Contract request {$action} eHealth API error", [
                'status' => $exception->response->status(),
                'contract_request_uuid' => $this->contractRequest->uuid,
            ]);
        } else {
            Log::error("Contract request {$action} error", [
                'message' => $exception->getMessage(),
                'contract_request_uuid' => $this->contractRequest->uuid,
            ]);
        }

        $message = $exception instanceof EHealthValidationException
            ? $exception->getTranslatedMessage()
            : __('contracts.action_error', ['message' => $exception->getMessage()]);

        Session::flash('error', $message);
    }

    private function statusValue(): string
    {
        $status = $this->contractRequest->status;

        if (is_object($status) && property_exists($status, 'value')) {
            return (string) $status->value;
        }

        return (string) $status;
    }

    /**
     * @return array<string, string>
     */
    private function resolveMedicalProgramNames(): array
    {
        if ($this->contractRequest->type !== Type::REIMBURSEMENT->value) {
            return [];
        }

        try {
            return dictionary()->medicalPrograms()
                ->pluck('name', 'id')
                ->all();
        } catch (Throwable $exception) {
            Log::warning('Failed to load medical program dictionary: '.$exception->getMessage());

            return [];
        }
    }

    private function syncDetailsFromEHealth(): bool
    {
        try {
            $contractType = strtolower((string) $this->contractRequest->type);
            $data = EHealth::contractRequest()->getDetails($contractType, $this->contractRequest->uuid)->validate();
            $data['sync_status'] = JobStatus::COMPLETED->value;
            $this->contractRequest = Repository::contractRequest()->saveFromEHealth($data, $this->contractRequest->type, details: true);
            $this->contractData = $this->normalizeContractData($this->contractRequest->data ?? []);

            try {
                $printout = EHealth::contractRequest()->getPrintoutContent($this->contractRequest->uuid, $contractType)->validate();
                $this->contractRequest->update(['printout_content' => $printout['printout_content']]);
            } catch (Throwable $exception) {
                Log::warning('Failed to read contract request printout', ['exception' => $exception::class]);
                Session::flash('warning', __('contracts.printout_unavailable'));
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Failed to read contract request details', ['exception' => $exception::class]);
            Session::flash('error', __('contracts.details_unavailable'));

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContractData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function render()
    {
        $idFormCode = $this->contractRequest->idForm
            ?? data_get($this->contractData, 'id_form');

        return view('livewire.contract-request.contract-request-show', [
            'contractRequest' => $this->contractRequest,
            'idFormName' => IdForm::resolveLabel($idFormCode, $this->contractRequest->type),
        ]);
    }
}
