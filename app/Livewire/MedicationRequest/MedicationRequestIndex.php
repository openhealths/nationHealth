<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Api\Job;
use App\Dto\MedicationDispense\Request as DispenseRequestData;
use App\Dto\MedicationDispense\Ehealth as DispenseEhealthData;
use App\Models\Employee\Employee;
use App\Repositories\EmployeeRepository;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class MedicationRequestIndex extends Component
{
    use WithFileUploads;

    public LegalEntity $legalEntity;

    public string $requestNumber = '';

    public string $code = '';

    public string $medicationQty = '';

    /** @var list<array<string, mixed>> */
    public array $searchResults = [];

    public bool $hasSearched = false;

    public ?string $errorMessage = null;

    public ?string $selectedRequestId = null;

    public bool $showSignatureModal = false;

    public ?string $actionType = 'dispense';

    /** @var array<string, mixed> */
    public array $form = [
        'knedp' => '',
        'keyContainerUpload' => null,
        'keyContainerFileName' => '',
        'password' => '',
    ];

    public function search(): void
    {
        abort_unless($this->userCanDispense(), 403);
        $this->validate([
            'requestNumber' => 'required|string',
        ], [], [
            'requestNumber' => 'номер електронного рецепта',
        ]);

        $this->errorMessage = null;
        $this->hasSearched = true;
        $this->selectedRequestId = null;
        $this->requestNumber = DispenseRequestData::formatNumber($this->requestNumber);

        try {
            $this->searchResults = $this->searchByRequestNumber($this->requestNumber);

            if ($this->searchResults === []) {
                $this->errorMessage = 'Електронний рецепт не знайдено.';
            } elseif (count($this->searchResults) === 1) {
                $this->selectRequest((string) ($this->searchResults[0]['id'] ?? $this->searchResults[0]['uuid'] ?? ''));
            }
        } catch (Throwable $exception) {
            Log::error('Pharmacy eRx search failed: '.$exception->getMessage());
            $this->errorMessage = 'Помилка під час пошуку: '.$exception->getMessage();
            $this->searchResults = [];
        }
    }

    public function selectRequest(string $requestId): void
    {
        $this->selectedRequestId = $requestId;
        $request = $this->selectedRequest();
        $qty = data_get($request, 'medication_qty') ?: data_get($request, 'dispense_request.quantity.value') ?: '';
        $this->medicationQty = $qty !== '' && $qty !== null ? (string) $qty : '';
        $this->code = '';
    }

    public function openDispenseSignature(): void
    {
        abort_unless($this->userCanDispense(), 403);
        try {
            $this->validate([
                'selectedRequestId' => 'required|string',
                'code' => 'required|string|min:4',
                'medicationQty' => 'required|numeric|min:0.01',
            ], [], [
                'selectedRequestId' => 'електронний рецепт',
                'code' => 'код погашення з СМС',
                'medicationQty' => 'кількість',
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->actionType = 'dispense';
        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        abort_unless($this->userCanDispense(), 403);
        try {
            $this->validate([
                'form.knedp' => 'required|string',
                'form.keyContainerUpload' => 'required',
                'form.password' => 'required|string',
                'selectedRequestId' => 'required|string',
                'code' => 'required|string|min:4',
                'medicationQty' => 'required|numeric|min:0.01',
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->searchResults = $this->searchByRequestNumber($this->requestNumber);
        $request = $this->selectedRequest();
        if ($request === null) {
            Session::flash('error', 'Електронний рецепт не знайдено. Повторіть пошук.');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $employee = app(EmployeeRepository::class)->pharmacyEmployee(Auth::user(), $this->legalEntity->id);
            $this->dispenseSelectedRequest($request, $employee);

            $this->showSignatureModal = false;
            $this->form['password'] = '';
            $this->form['keyContainerUpload'] = null;
            $this->form['keyContainerFileName'] = '';
            Session::flash('success', 'Електронний рецепт успішно погашено в аптеці.');
            $this->search();
        } catch (EHealthValidationException $exception) {
            $exception->report();
            Session::flash('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('Pharmacy eRx dispense failed: '.$exception->getMessage());
            Session::flash('error', 'Не вдалося погасити рецепт: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    protected function searchByRequestNumber(string $number): array
    {
        $payload = EHealth::medicationRequest()->searchByPharmacy(['request_number' => DispenseRequestData::formatNumber($number)])->getData();
        $items = $payload['data'] ?? (isset($payload[0]) ? $payload : ($payload !== [] ? [$payload] : []));

        return is_array($items) ? array_values(array_filter($items, static fn ($item): bool => is_array($item))) : [];
    }

    protected function dispenseSelectedRequest(array $rawRequest, ?Employee $employee): array
    {
        if ($employee === null || empty($employee->uuid)) {
            throw new \RuntimeException('Не знайдено співробітника аптеки для погашення рецепта.');
        }
        $divisionUuid = $employee->division?->uuid;
        if (empty($divisionUuid)) {
            throw new \RuntimeException('У співробітника аптеки не вказано місце надання послуг.');
        }
        $mapper = app(ObjectMapperInterface::class);
        $request = $mapper->map(new Collection($rawRequest), DispenseRequestData::class);
        if ($request->id === '') {
            throw new \InvalidArgumentException('Немає ідентифікатора електронного рецепта.');
        }
        $medicationId = $request->medicationId;
        $minimumQuantity = null;
        if ($request->programId !== '') {
            try {
                $response = EHealth::medicationRequest()->qualify($request->id, [
                    'division_id' => $divisionUuid,
                    'programs' => [['id' => $request->programId]],
                ])->getData();
                $participant = data_get($response, '0.participants.0') ?? data_get($response, 'data.0.participants.0');
                if (!empty($participant['medication_id'])) {
                    $medicationId = (string) $participant['medication_id'];
                }
                if (!empty($participant['package_min_qty'])) {
                    $minimumQuantity = (float) $participant['package_min_qty'];
                }
            } catch (Throwable $exception) {
                Log::warning('Qualify lookup failed, continuing with request data: '.$exception->getMessage());
            }
        }
        $payload = ['medication_dispense' => $mapper->map($this, new DispenseEhealthData(
            $request,
            $divisionUuid,
            now()->toDateString(),
            $medicationId,
            $minimumQuantity
        ))->toArray()];
        if (trim($this->code) !== '') {
            $payload['code'] = trim($this->code);
        }
        $created = EHealth::medicationDispense()->create($payload)->getData();
        $entity = $created['data'] ?? $created;
        if (isset($entity[0]) && is_array($entity[0])) {
            $entity = $entity[0];
        }
        $id = (string) ($entity['id'] ?? $entity['uuid'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('ЕСОЗ не повернула ідентифікатор відпуску ліків.');
        }
        if (in_array(strtoupper((string) ($entity['status'] ?? '')), ['PROCESSED', 'COMPLETED'], true)) {
            return is_array($entity) ? $entity : (array) $created;
        }
        $signed = signatureService()->signData(
            is_array($entity) ? $entity : $payload['medication_dispense'],
            $this->form['password'],
            $this->form['knedp'],
            $this->form['keyContainerUpload'] ?? null,
            $employee->party?->taxId,
        );
        $processed = EHealth::medicationDispense()->process($id, [
            'signed_medication_dispense' => $signed,
            'signed_content_encoding' => 'base64',
        ]);
        $final = app(Job::class)->resolve($processed->getData());

        return is_array($final) ? $final : (array) $processed->getData();
    }

    public function updatedFormKeyContainerUpload(): void
    {
        $upload = $this->form['keyContainerUpload'] ?? null;

        if ($upload && method_exists($upload, 'getClientOriginalName')) {
            $this->form['keyContainerFileName'] = $upload->getClientOriginalName();
        } elseif ($upload === null) {
            $this->form['keyContainerFileName'] = '';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectedRequest(): ?array
    {
        if ($this->selectedRequestId === null || $this->selectedRequestId === '') {
            return null;
        }

        foreach ($this->searchResults as $result) {
            $id = (string) ($result['id'] ?? $result['uuid'] ?? '');
            if ($id === $this->selectedRequestId) {
                return $result;
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.medication-request.medication-request-index');
    }

    private function userCanDispense(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->can('medication_dispense:write')
            || $user->can('medication_dispense:process')
            || $user->can('medication_request:details_pharm');
    }
}
