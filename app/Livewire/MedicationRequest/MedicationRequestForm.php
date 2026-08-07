<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest;

use App\Models\LegalEntity;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Exception;
use Livewire\Component;

class MedicationRequestForm extends Component
{
    public LegalEntity $legalEntity;

    public string $patientId = '';
    public string $medicalProgram = '';
    public string $dosageInstruction = '';
    public string $duration = '';

    public bool $isDraftCreated = false;
    public ?string $draftId = null;
    public ?string $statusMessage = null;

    protected array $rules = [
        'patientId' => 'required|string',
        'medicalProgram' => 'required|string',
        'dosageInstruction' => 'required|string',
        'duration' => 'required|numeric|min:1',
    ];

    public function preQualify(MedicationRequestLifecycleService $service)
    {
        $this->validate();

        try {
            $payload = [
                'person_id' => $this->patientId,
                'medical_program_id' => $this->medicalProgram, // Assuming string maps to ID for simplicity
                // Mock payload structure based on typical eHealth API
                'programs' => [
                    ['id' => $this->medicalProgram]
                ]
            ];

            $response = $service->preQualify($payload);

            $this->statusMessage = "PreQualify успішно пройдено. Можна створювати чернетку.";
        } catch (Exception $e) {
            $this->statusMessage = "Помилка PreQualify: " . $e->getMessage();
        }
    }

    public function createDraft(MedicationRequestLifecycleService $service)
    {
        $this->validate();

        try {
            $payload = [
                'person_id' => $this->patientId,
                'medical_program_id' => $this->medicalProgram,
                'dosage_instruction' => $this->dosageInstruction,
                'dispense_request' => [
                    'expected_supply_duration' => [
                        'value' => (int)$this->duration,
                        'system' => 'http://unitsofmeasure.org',
                        'code' => 'd'
                    ]
                ]
            ];

            $response = $service->createDraft($payload);

            $this->isDraftCreated = true;
            $this->draftId = $response['id'] ?? 'dummy-uuid-1234';

            $this->statusMessage = "Чернетка створена (ID: {$this->draftId}). Очікується підпис КЕП.";
        } catch (Exception $e) {
            $this->statusMessage = "Помилка створення чернетки: " . $e->getMessage();
        }
    }

    public function sign(MedicationRequestLifecycleService $service)
    {
        if (!$this->isDraftCreated || !$this->draftId) {
            $this->statusMessage = "Спершу створіть чернетку!";

            return;
        }

        try {
            // Mock KEП signing payload
            $payload = [
                'signed_medication_request_request' => base64_encode(json_encode(['id' => $this->draftId, 'status' => 'ACTIVE'])),
                'signed_content_encoding' => 'base64',
            ];

            $service->sign($this->draftId, $payload);

            $this->statusMessage = "Рецепт успішно підписано КЕП та переведено у статус «Активний»!";

            // Dispatch event to parent to refresh list
            $this->dispatch('medication-request-created');
        } catch (Exception $e) {
            $this->statusMessage = "Помилка підписання: " . $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.medication-request.medication-request-form');
    }
}
