<?php

declare(strict_types=1);

namespace App\Livewire\Pharmacy;

use Livewire\Component;
use App\Classes\eHealth\Api\MedicationDispense as MedicationDispenseApi;
use App\Classes\eHealth\Api\DeviceDispense as DeviceDispenseApi;
use App\Services\Pharmacy\Domain\Dictionaries\PharmacyDictionaries;
use App\Services\Pharmacy\Domain\Services\PharmacyCalculationEngine;
use App\Services\Pharmacy\Domain\Services\PharmacyValidationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Livewire Компонент: Робоче місце фармацевта (п. 3.5 ТЗ)
 */
class PharmacistWorkstation extends Component
{
    public string $searchType = 'medication'; // 'medication' | 'device'
    public string $searchQuery = '';
    
    // Завантажені дані запиту/рецепта
    public ?array $activeRequest = null;
    public array $requestValidation = ['can_dispense' => true, 'reason' => null, 'details' => []];
    
    // Кваліфікація та список доступних товарів
    public array $qualifiedParticipants = [];
    public array $selectedParticipants = [];
    public ?string $selectedRegistryNumber = null;
    public string $qualificationError = '';
    
    // Поточна сесія погашення
    public ?string $activeDispenseId = null;
    public ?int $sessionExpiresAt = null; // timestamp автотермінації (10 або 20 хв)
    public bool $isKepRequired = true;
    
    // Фіскальні дані
    public float $paymentAmount = 0.0;
    public string $paymentId = '';
    public string $verificationCode = '';
    public string $dispenseNote = '';
    public ?string $whenHandedOver = null;
    
    // Модальні вікна резервування / відхилення
    public bool $showReservationModal = false;
    public string $reservationWarningText = '';
    public string $blockReasonCode = 'EXTEMPORAL_PRODUCTION';
    public string $blockReason = '';
    public ?string $blockedTo = null;
    
    public bool $showRejectModal = false;
    public string $rejectReasonCode = 'OUT_OF_STOCK';
    public string $rejectReason = '';

    protected PharmacyCalculationEngine $calcEngine;
    protected PharmacyValidationService $validationService;

    public function boot(): void
    {
        $this->calcEngine = new PharmacyCalculationEngine();
        $this->validationService = new PharmacyValidationService();
    }

    public function render()
    {
        return view('livewire.pharmacy.pharmacist-workstation', [
            'blankTypes' => PharmacyDictionaries::MR_BLANK_TYPES,
            'rejectReasons' => PharmacyDictionaries::MEDICATION_REQUEST_REJECT_REASON,
            'blockReasons' => PharmacyDictionaries::MEDICATION_REQUEST_BLOCK_REASON,
            'unblockReasons' => PharmacyDictionaries::MEDICATION_REQUEST_UNBLOCK_REASON,
        ]);
    }

    /**
     * Пошук ЕР на ЛЗ або е-запиту на медичний виріб
     */
    public function search(): void
    {
        $this->resetState();
        $this->validate(['searchQuery' => 'required|string|min:4']);

        $legalEntityId = Auth::user()->legal_entity_id ?? '';

        try {
            if ($this->searchType === 'medication') {
                $response = MedicationDispenseApi::getMedicationRequest($this->searchQuery);
                $this->activeRequest = $response['data'] ?? $response;
                $this->requestValidation = $this->validationService->validateMedicationRequestStatus($this->activeRequest, $legalEntityId);
                
                if ($this->requestValidation['can_dispense']) {
                    $this->qualifyMedication();
                }
            } else {
                $response = DeviceDispenseApi::searchDeviceRequest(['requisition' => $this->searchQuery]);
                $items = $response['data'] ?? $response;
                $this->activeRequest = is_array($items) && isset($items[0]) ? $items[0] : $items;
                $this->requestValidation = $this->validationService->validateDeviceRequestStatus($this->activeRequest);

                if ($this->requestValidation['can_dispense']) {
                    $this->qualifyDevice();
                }
            }
        } catch (\Throwable $e) {
            $this->qualificationError = $this->validationService->translateQualificationError($e->getMessage(), null, $this->searchType === 'device');
            Log::channel('e_health_errors')->error("Pharmacy search error: {$e->getMessage()}");
        }
    }

    /**
     * Кваліфікація ЛЗ
     */
    protected function qualifyMedication(): void
    {
        try {
            $programId = $this->activeRequest['medical_program']['id'] ?? '';
            $divisionId = Auth::user()->division_id ?? '';

            $res = MedicationDispenseApi::qualifyMedicationRequest($this->activeRequest['id'], [
                'medical_program_id' => $programId,
                'division_id' => $divisionId,
            ]);

            $this->qualifiedParticipants = $res['data']['participants'] ?? [];
            $this->isKepRequired = !($this->activeRequest['medical_program_setting']['skip_medication_dispense_sign'] ?? false);
        } catch (\Throwable $e) {
            $this->qualificationError = $this->validationService->translateQualificationError($e->getMessage());
        }
    }

    /**
     * Кваліфікація медичних виробів
     */
    protected function qualifyDevice(): void
    {
        try {
            $programId = $this->activeRequest['program']['id'] ?? $this->activeRequest['program']['value'] ?? '';
            $locationId = Auth::user()->division_id ?? '';

            $res = DeviceDispenseApi::qualifyDeviceRequest($this->activeRequest['id'], [
                'programs' => [['value' => $programId]],
                'location' => $locationId,
            ]);

            $this->qualifiedParticipants = $res['data']['participants'] ?? [];
            $this->isKepRequired = true;
        } catch (\Throwable $e) {
            $this->qualificationError = $this->validationService->translateQualificationError($e->getMessage(), null, true);
        }
    }

    /**
     * Вибір товарної позиції (жорстко з одного реєстру)
     */
    public function selectParticipant(int $index, float $sellPrice): void
    {
        $item = $this->qualifiedParticipants[$index] ?? null;
        if (!$item) return;

        $targetReg = $item['registry_number'] ?? 'DEFAULT';
        if ($this->selectedRegistryNumber && $this->selectedRegistryNumber !== $targetReg) {
            $this->dispatch('notify', ['type' => 'error', 'text' => 'Вибір дозволено тільки з одного реєстру відшкодування!']);
            return;
        }

        $this->selectedRegistryNumber = $targetReg;
        $this->selectedParticipants[$index] = array_merge($item, ['sell_price' => $sellPrice]);
    }

    /**
     * Відкриття модалки резервування
     */
    public function openReservationModal(): void
    {
        $dispenseValidTo = $this->activeRequest['dispensed_valid_to'] ?? '';
        $this->reservationWarningText = $this->validationService->getReservationWarningText($this->blockedTo, $dispenseValidTo);
        $this->showReservationModal = true;
    }

    /**
     * Підтвердження резервування
     */
    public function confirmReservation(): void
    {
        try {
            MedicationDispenseApi::blockMedicationRequest($this->activeRequest['id'], [
                'block_reason_code' => $this->blockReasonCode,
                'block_reason' => $this->blockReason,
                'blocked_to' => $this->blockedTo,
            ]);
            $this->showReservationModal = false;
            $this->search();
            $this->dispatch('notify', ['type' => 'success', 'text' => 'Рецепт успішно зарезервовано']);
        } catch (\Throwable $e) {
            $this->dispatch('notify', ['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    /**
     * Відхилення рецепта фармацевтом
     */
    public function confirmRejection(): void
    {
        try {
            MedicationDispenseApi::rejectMedicationRequest($this->activeRequest['id'], [
                'reject_reason_code' => $this->rejectReasonCode,
                'reject_reason' => $this->rejectReason,
            ]);
            $this->showRejectModal = false;
            $this->search();
            $this->dispatch('notify', ['type' => 'info', 'text' => 'Рецепт відхилено']);
        } catch (\Throwable $e) {
            $this->dispatch('notify', ['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    protected function resetState(): void
    {
        $this->activeRequest = null;
        $this->qualifiedParticipants = [];
        $this->selectedParticipants = [];
        $this->selectedRegistryNumber = null;
        $this->qualificationError = '';
        $this->activeDispenseId = null;
        $this->sessionExpiresAt = null;
    }
}
