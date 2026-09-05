<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest;

use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Services\MedicalEvents\MedicationDispenseLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    public function search(MedicationDispenseLifecycleService $service): void
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
        $this->requestNumber = $service->formatRequestNumber($this->requestNumber);

        try {
            $this->searchResults = $service->searchByRequestNumber($this->requestNumber);

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
            $this->flashOutcome('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->actionType = 'dispense';
        $this->showSignatureModal = true;
    }

    public function sign(MedicationDispenseLifecycleService $service): void
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
            $this->flashOutcome('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->searchResults = $service->searchByRequestNumber($this->requestNumber);
        $request = $this->selectedRequest();
        if ($request === null) {
            $this->flashOutcome('error', 'Електронний рецепт не знайдено. Повторіть пошук.');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $employee = Auth::user()?->employees()
                ->where('legal_entity_id', $this->legalEntity->id)
                ->whereIn('employee_type', ['PHARMACIST', 'PHARMACIST_ADMIN'])
                ->where('status', \App\Enums\Person\Status::APPROVED)
                ->with(['division', 'party'])
                ->first()
                ?? Auth::user()?->employees()
                    ->where('legal_entity_id', $this->legalEntity->id)
                    ->whereNotNull('division_id')
                    ->where('status', \App\Enums\Person\Status::APPROVED)
                    ->with(['division', 'party'])
                    ->first()
                ?? Auth::user()?->employees()
                    ->where('legal_entity_id', $this->legalEntity->id)
                    ->with(['division', 'party'])
                    ->first();

            $service->dispense(
                $request,
                [
                    'code' => $this->code,
                    'medication_qty' => $this->medicationQty,
                    'password' => $this->form['password'],
                    'knedp' => $this->form['knedp'],
                    'keyContainerUpload' => $this->form['keyContainerUpload'],
                ],
                $service->resolvePharmacyEmployeeContext($employee)
            );

            $this->showSignatureModal = false;
            $this->form['password'] = '';
            $this->form['keyContainerUpload'] = null;
            $this->form['keyContainerFileName'] = '';
            $this->flashOutcome('success', 'Електронний рецепт успішно погашено в аптеці.');
            $this->search($service);
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('Pharmacy eRx dispense failed: '.$exception->getMessage());
            $this->flashOutcome('error', 'Не вдалося погасити рецепт: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
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

    protected function flashOutcome(string $type, string $message): void
    {
        session()->flash($type, $message);
        $this->dispatch('flashMessage', ['message' => $message, 'type' => $type]);
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
