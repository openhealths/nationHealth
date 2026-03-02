<?php

declare(strict_types=1);

namespace App\Livewire\Contract;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Carbon\Carbon;
use Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class ContractComponent extends Component
{
    use FormTrait;
    use WithFileUploads;

    public string $legalEntityName;
    public string $contractorFullName;
    public bool $showSignatureModal = false;

    abstract protected function getContractType(): string;
    abstract protected function collectPayload(array $validatedValidatedData): array;

    public function baseMount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $legalEntity = $legalEntity->fresh();

        $this->form->contractorLegalEntityId = $legalEntity->uuid;

        $edrData = is_string($legalEntity->edr)
            ? json_decode($legalEntity->edr, true)
            : (is_array($legalEntity->edr) ? $legalEntity->edr : []);

        $this->legalEntityName = $edrData['name'] ?? 'Невідома назва';

        $this->form->contractorPaymentDetails = [
              'bankName' => '',
              'MFO' => '',
              'payerAccount' => '',
          ];

        $address = $legalEntity->addresses()->where('type', 'REGISTRATION')->first();

        if ($address) {
            $this->form->contractorBase = sprintf(
                '%s, %s, %s, %s',
                $address->zip ?? '',
                $address->settlement ?? '',
                $address->street ?? '',
                $address->building ?? ''
            );
        } else {
            $this->form->contractorBase = $edrData['address'] ?? '';
        }

        $contractorData = Auth::user()->employees()
            ->contractors($legalEntity->id)
            ->with('party')
            ->first();

        if (empty($contractorData)) {
            abort(403, __('Співробітника з відповідними доступами не знайдено.'));
        }

        $party = $contractorData->party;
        $this->contractorFullName = $party->fullName ??
            trim("{$party->last_name} {$party->first_name} {$party->second_name}");

        $this->form->contractorOwnerId = $contractorData->uuid;
    }

    public function openSignatureModal(): void
    {
        $this->showSignatureModal = true;
    }

    public function save(): void
    {
        try {
            $validatedData = $this->form->validate();
            $dataToSave = $validatedData;

            if (isset($dataToSave['statuteMd5']) && $dataToSave['statuteMd5'] instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                $dataToSave['statuteMd5'] = md5_file($dataToSave['statuteMd5']->getRealPath());
            }
            if (isset($dataToSave['additionalDocumentMd5']) && $dataToSave['additionalDocumentMd5'] instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                $dataToSave['additionalDocumentMd5'] = md5_file($dataToSave['additionalDocumentMd5']->getRealPath());
            }

            ContractRequest::create([
                'contractor_legal_entity_id' => $this->form->contractorLegalEntityId,
                'contractor_owner_id' => $this->form->contractorOwnerId,
                'data' => json_encode($dataToSave, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'DRAFT',
                'contractor_base' => $dataToSave['contractorBase'] ?? 'N/A',
                'contractor_payment_details' => json_encode(
                    $dataToSave['contractorPaymentDetails'] ?? [],
                    JSON_UNESCAPED_UNICODE
                ),
                'start_date' => Carbon::parse($dataToSave['startDate']),
                'end_date' => Carbon::parse($dataToSave['endDate']),
                'id_form' => $dataToSave['idForm'] ?? 'unknown',
                'type' => $this->getContractType(),
                'contractor_signed' => false,
            ]);

            Session::flash('success', __('Чернетку збережено успішно (локально).'));

        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
        } catch (\Exception $e) {
            Session::flash('error', 'Помилка збереження: ' . $e->getMessage());
        }
    }

    public function create(): void
    {
        // 1. Livewire Form Validation
        try {
            $validatedData = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        // 2.Initialization (API-005-012-0004 Public. Initialize Contract Request)
        // Getting a URL for uploading files
        try {
            $response = EHealth::contractRequest()->initialize($this->getContractType());
            $eHealthData = $response->getData();

            $contractRequestId = $eHealthData['uuid'] ?? $eHealthData['id'] ?? null;
            $statuteUrl = $eHealthData['statute_url'] ?? null;
            $additionalDocUrl = $eHealthData['additional_document_url'] ?? null;

            if (!$contractRequestId) {
                throw new \RuntimeException('Не вдалося отримати ID запиту від ЕСОЗ.');
            }

        } catch (\Exception $e) {
            $this->handleEHealthError($e);

            return;
        }

        // 3. Uploading files to S3 (if any)
        try {
            // --- STATUS ---
            if ($statuteUrl && isset($this->form->statuteMd5)) { // In form, it is a file object
                $fileObject = $this->form->statuteMd5;
                $filePath = $fileObject->getRealPath();
                $fileContent = file_get_contents($filePath);

                // Counting the MD5 of a real file for payload
                $md5Hash = md5_file($filePath);

                // Sending a file (PUT binary body)
                // Using logic similar to PersonComponent
                $uploadResp = Http::withBody($fileContent, $fileObject->getMimeType())
                    ->put($statuteUrl);

                if ($uploadResp->failed()) {
                    Log::error('Statute Upload Failed', ['body' => $uploadResp->body()]);
                    throw new \RuntimeException('Помилка завантаження файлу Статуту в сховище ЕСОЗ.');
                }

                // Storing the real hash for payload
                $validatedData['statuteMd5'] = $md5Hash;
            }

            // --- Additional document ---
            if ($additionalDocUrl && isset($this->form->additionalDocumentMd5)) {
                $fileObject = $this->form->additionalDocumentMd5;
                $filePath = $fileObject->getRealPath();
                $fileContent = file_get_contents($filePath);

                $md5Hash = md5_file($filePath);

                $uploadResp = Http::withBody($fileContent, $fileObject->getMimeType())
                    ->put($additionalDocUrl);

                if ($uploadResp->failed()) {
                    Log::error('AddDoc Upload Failed', ['body' => $uploadResp->body()]);
                    throw new \RuntimeException('Помилка завантаження Додаткового документа в сховище ЕСОЗ.');
                }

                $validatedData['additionalDocumentMd5'] = $md5Hash;
            }

        } catch (\Exception $e) {
            Session::flash('error', 'Помилка при завантаженні файлів: ' . $e->getMessage());

            return;
        }

        // 4. Form a payload (now real MD5 will get here)
        // The collectPayload method in the child class must use $validatedData['statuteMd5']
        $payload = $this->collectPayload($validatedData);

        // 5. Signing (QES)
        try {
            $signingData = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', 'Помилка параметрів КЕП: ' . $exception->validator->errors()->first());

            return;
        }

        try {
            $signedContent = signatureService()->signData(
                $payload,
                $signingData['password'],
                $signingData['knedp'],
                $signingData['keyContainerUpload'],
                Auth::user()->party->taxId
            );

        } catch (\Exception $e) {
            Session::flash('error', 'Помилка накладання КЕП: ' . $e->getMessage());

            return;
        }

        // 6. Sending a request for creation (API-005-012-0005 Public. Create Contract Request)
        try {
            $response = EHealth::contractRequest()->create(
                $contractRequestId,
                $this->getContractType(),
                [
                    'signed_content' => $signedContent,
                    'signed_content_encoding' => 'base64',
                ]
            );

            $createdContractData = $response->getData();

            Repository::contract()->saveFromEHealth($createdContractData);

            Session::flash('success', 'Запит на контракт успішно створено та збережено!');
            $this->showSignatureModal = false;

            // Edit to the list
            $this->redirectRoute('contract.index', legalEntity(), navigate: true);

        } catch (\Exception $e) {
            $this->handleEHealthError($e);
        }
    }

    protected function handleEHealthError(\Exception $exception): void
    {
        $msg = $exception instanceof EHealthValidationException
            ? $exception->getFormattedMessage()
            : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
        Session::flash('error', $msg);
    }

    protected function logConnectionError(\Exception $e, string $msg): void
    {
        Log::error($msg . ': ' . $e->getMessage());
    }
}
