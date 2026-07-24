<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Classes\eHealth\EHealth;
use App\Enums\Contract\IdForm;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Contract\Forms\ContractRequestSigningForm as SigningForm;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class ContractRequestShow extends Component
{
    use WithFileUploads;

    public ContractRequest $contractRequest;
    public SigningForm $form;
    public array $contractData = [];
    public array $medicalProgramNames = [];
    public bool $showSignatureModal = false;
    public ?string $pendingAction = null;

    public function mount(LegalEntity $legalEntity, ContractRequest $contractRequest): void
    {
        $this->contractRequest = $contractRequest;

        if ($this->contractRequest->uuid && $this->contractRequest->type) {
            $this->syncDetailsFromEHealth();
        }

        $this->contractData = $this->normalizeContractData($this->contractRequest->data ?? []);
        $this->medicalProgramNames = $this->resolveMedicalProgramNames();
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
        $this->authorize('approve', $this->contractRequest);

        if (!$this->canApproveContractRequest()) {
            Session::flash('error', __('contracts.approve_unavailable_for_status'));

            return;
        }

        $this->pendingAction = 'approve';
        $this->showSignatureModal = true;
    }

    public function openSignModal(): void
    {
        $this->authorize('sign', $this->contractRequest);

        if (!$this->canSignContractRequest()) {
            Session::flash('error', __('contracts.sign_unavailable_for_status'));

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
        $this->authorize('approve', $this->contractRequest);

        if (!$this->canApproveContractRequest()) {
            Session::flash('error', __('contracts.approve_unavailable_for_status'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        try {
            $this->syncDetailsFromEHealth();
            $dataToSign = $this->buildJsonDataToSign();
            $signedContent = signatureService()->signData(
                $dataToSign,
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
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

            $this->persistEHealthResponse($response->getData());
            $this->showSignatureModal = false;
            $this->pendingAction = null;
            Session::flash('success', __('contracts.approve_success'));
        } catch (\Throwable $exception) {
            $this->handleActionError($exception, 'approve');
        }
    }

    public function sign(): void
    {
        $this->authorize('sign', $this->contractRequest);

        if (!$this->canSignContractRequest()) {
            Session::flash('error', __('contracts.sign_unavailable_for_status'));

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

            $this->persistEHealthResponse($response->getData());
            $this->showSignatureModal = false;
            $this->pendingAction = null;
            Session::flash('success', __('contracts.sign_success'));
        } catch (\Throwable $exception) {
            $this->handleActionError($exception, 'sign');
        }
    }

    /**
     * Builds signed content for sign_msp: co-sign NHS content when available, else sign JSON.
     *
     * At NHS_SIGNED, eHealth usually returns partially signed PKCS7 via getSignedContent().
     * That blob must be co-signed with {@see SignatureService::signBase64Payload()}.
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
            $signedContentData = $signedContentResponse->getData();
            $base64Payload = $signedContentData['content'] ?? $signedContentData['signed_content'] ?? null;

            if (is_string($base64Payload) && $base64Payload !== '') {
                return signatureService()->signBase64Payload(
                    $base64Payload,
                    $validated['password'],
                    $validated['knedp'],
                    $validated['keyContainerUpload'],
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

        $this->syncDetailsFromEHealth();

        return signatureService()->signData(
            $this->buildJsonDataToSign(),
            $validated['password'],
            $validated['knedp'],
            $validated['keyContainerUpload'],
            Auth::user()->party->taxId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJsonDataToSign(): array
    {
        $data = $this->normalizeContractData($this->contractRequest->fresh()->data ?? []);

        if (!empty($this->contractRequest->printout_content)) {
            $data['printout_content'] = $this->contractRequest->printout_content;
        }

        if (empty($data)) {
            throw new \RuntimeException(__('contracts.sign_data_unavailable'));
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

    private function handleActionError(\Throwable $exception, string $action): void
    {
        if ($exception instanceof EHealthResponseException) {
            Log::error("Contract request {$action} eHealth API error", [
                'status' => $exception->response->status(),
                'body' => $exception->response->body(),
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
        try {
            return dictionary()->medicalPrograms()
                ->pluck('name', 'id')
                ->all();
        } catch (\Throwable $exception) {
            Log::warning('Failed to load medical program dictionary: '.$exception->getMessage());

            return [];
        }
    }

    private function syncDetailsFromEHealth(): void
    {
        try {
            $contractType = strtolower((string) $this->contractRequest->type);

            $response = EHealth::contractRequest()->getDetails($contractType, $this->contractRequest->uuid);

            $ehealthData = $response->getData();

            if (empty($ehealthData)) {
                return;
            }

            $printoutContent = null;

            try {
                $printoutResponse = EHealth::contractRequest()->getPrintoutContent($this->contractRequest->uuid, $contractType);
                $printoutData = $printoutResponse->getData();
                $printoutContent = $printoutData['content'] ?? null;
            } catch (\Exception $exception) {
                Log::warning('Failed to fetch Contract Request printout content: ' . $exception->getMessage());
            }

            $this->contractRequest->update([
                'contractor_base' => $ehealthData['contractor_base'] ?? $this->contractRequest->contractorBase,
                'contractor_payment_details' => $ehealthData['contractor_payment_details'] ?? null,
                'contractor_divisions' => $ehealthData['contractor_divisions'] ?? null,
                'external_contractors' => $ehealthData['external_contractors'] ?? null,
                'nhs_signer_id' => $ehealthData['nhs_signer']['id'] ?? $ehealthData['nhs_signer']['uuid'] ?? null,
                'nhs_signer_base' => $ehealthData['nhs_signer_base'] ?? null,
                'nhs_contract_price' => $ehealthData['nhs_contract_price'] ?? null,
                'nhs_payment_method' => $ehealthData['nhs_payment_method'] ?? null,
                'id_form' => $ehealthData['id_form'] ?? $this->contractRequest->idForm,
                'contract_number' => $ehealthData['contract_number'] ?? $this->contractRequest->contractNumber,
                'start_date' => $ehealthData['start_date'] ?? $this->contractRequest->startDate,
                'end_date' => $ehealthData['end_date'] ?? $this->contractRequest->endDate,
                'status' => $ehealthData['status'] ?? $this->contractRequest->status,
                'status_reason' => $ehealthData['status_reason'] ?? $this->contractRequest->statusReason,
                'inserted_at' => !empty($ehealthData['inserted_at'])
                    ? \Illuminate\Support\Carbon::parse($ehealthData['inserted_at'])
                    : $this->contractRequest->insertedAt,
                'printout_content' => $printoutContent ?? $this->contractRequest->printoutContent,
                'data' => $ehealthData,
            ]);

            $this->contractRequest->refresh();
            $this->contractRequest = $this->contractRequest->fresh();
        } catch (\Exception $exception) {
            Log::warning('Failed to fetch Contract Request details: ' . $exception->getMessage());
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
            'idFormName' => IdForm::resolveLabel($idFormCode, $this->contractRequest->type),
        ]);
    }
}
