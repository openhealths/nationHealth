<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Throwable;

trait PrintsMedicationRequests
{
    use ResolvesActiveMedicationRequest;

    protected function fetchMedicationRequestPrintout(string $personId, string $prescriptionId): array|string|null
    {
        try {
            $activeId = $this->resolveActiveMedicationRequestId($personId, $prescriptionId);
            $response = EHealth::person()->getMedicationRequestPrintoutForm($personId, $activeId);
            $data = $response->getData();

            if (is_array($data) && isset($data['printout_form'])) {
                return $data['printout_form'];
            }

            if (is_array($data) && isset($data['data'])) {
                $item = $data['data'];
                if (is_array($item) && isset($item['printout_form'])) {
                    return $item['printout_form'];
                }

                return $item;
            }

            return $data;
        } catch (Throwable $e) {
            Log::warning('Failed to fetch printout from eHealth: ' . $e->getMessage());

            return null;
        }
    }

    protected function medicationRequestPrintoutHtml(
        CarePlan $carePlan,
        string $prescriptionId,
        ?string $signatureText = null,
        ?array $ehealthData = null,
        ?string $fallbackDoctorName = null
    ): string {
        $record = app(MedicationRequestRepository::class)->findByUuid($prescriptionId);
        if ($record && empty($record->dosageInstructions)) {
            $record->loadMissing('dosageInstructions');
        }

        $requestNumber = $ehealthData['request_number'] ?? ($ehealthData['medication_request']['request_number'] ?? ($record?->request_number ?: $prescriptionId));

        $patientName = null;
        if ($carePlan->person) {
            $person = $carePlan->person;
            $patientName = $person->full_name ?? null;
            if (empty(trim((string) $patientName)) && $person->primaryName) {
                $patientName = trim($person->primaryName->lastName . ' ' . $person->primaryName->firstName . ' ' . ($person->primaryName->secondName ?? ''));
            }
            if (empty(trim((string) $patientName))) {
                $patientName = trim(($person->last_name ?? '') . ' ' . ($person->first_name ?? '') . ' ' . ($person->second_name ?? ''));
            }
        }
        if (empty(trim((string) $patientName)) || $patientName === 'Пацієнт') {
            $ePerson = $ehealthData['person'] ?? ($record?->ehealth_payload['person'] ?? null);
            if (is_array($ePerson)) {
                $patientName = trim(($ePerson['last_name'] ?? '') . ' ' . ($ePerson['first_name'] ?? '') . ' ' . ($ePerson['second_name'] ?? ''));
            } elseif (!empty($ehealthData['person']['name'])) {
                $patientName = $ehealthData['person']['name'];
            }
        }
        if (empty(trim((string) $patientName))) {
            $patientName = 'Пацієнт';
        }

        $patientBirthDate = $carePlan->person?->birth_date ? Carbon::parse($carePlan->person->birth_date)->format('d.m.Y') : ($ehealthData['person']['birth_date'] ?? ($record?->ehealth_payload['person']['birth_date'] ?? '—'));
        if ($patientBirthDate !== '—' && !str_contains((string) $patientBirthDate, '.')) {
            try {
                $patientBirthDate = Carbon::parse((string) $patientBirthDate)->format('d.m.Y');
            } catch (Exception $e) {
                // keep original
            }
        }

        $startDate = $record?->started_at ? Carbon::parse($record->startedAt)->format('d.m.Y') : ($ehealthData['created_at'] ?? now()->format('d.m.Y'));
        $endDate = $record?->ended_at ? Carbon::parse($record->endedAt)->format('d.m.Y') : ($ehealthData['ended_at'] ?? '—');

        $author = null;
        if ($record && !empty($record->employeeId)) {
            $field = is_numeric($record->employeeId) ? 'id' : 'uuid';
            $author = Employee::where($field, $record->employeeId)->first();
        }

        $doctorName = $author?->party?->full_name ?? ($author?->full_name ?? ($ehealthData['employee']['name'] ?? ($record?->ehealth_payload['employee']['name'] ?? ($fallbackDoctorName ?: '—'))));
        $facilityName = $ehealthData['division']['name'] ?? ($record?->ehealth_payload['division']['name'] ?? (legalEntity()?->name ?? 'Медичний заклад'));

        $medicationName = $ehealthData['medication_name']
            ?? ($ehealthData['medication']['name']
            ?? ($ehealthData['medication_info']['medication_name']
            ?? ($record?->ehealth_payload['medication_info']['medication_name']
            ?? ($record?->ehealth_payload['medication_name']
            ?? ($record?->ehealth_payload['medication']['name']
            ?? 'Лікарський засіб')))));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $medicationName)) {
            $medicationName = 'Лікарський засіб';
        }

        $medicationQty = $ehealthData['medication_qty'] ?? ($ehealthData['medication']['qty'] ?? ($record?->medication_qty ? "{$record->medicationQty} од." : '—'));
        $programName = $ehealthData['medical_program_name']
            ?? ($ehealthData['medical_program']['name']
            ?? ($record?->ehealth_payload['medical_program']['name']
            ?? ($record?->ehealth_payload['medical_program_name']
            ?? ($record?->medication_program_id ? ($record->medicationProgramId === '5e3e2307-8898-4428-a400-e3776a39d56f' ? 'Реімбурсація (Доступні ліки)' : 'Державна програма / Реімбурсація') : 'За власні кошти'))));

        $instructionsList = [];
        if ($record && $record->dosageInstructions) {
            foreach ($record->dosageInstructions as $instr) {
                $text = $instr->text ?: $instr->patient_instruction;
                if ($text) {
                    $instructionsList[] = $text;
                }
            }
        }
        $dosageText = !empty($instructionsList) ? implode('; ', $instructionsList) : ($ehealthData['dosage_instruction'] ?? ($signatureText ?? 'За призначенням лікаря'));
        $otpInfo = $ehealthData['confirmation_code'] ?? 'Відправлено в SMS-повідомленні на номер телефону пацієнта';

        return view('livewire.medication-request.printout', compact('requestNumber', 'patientName', 'patientBirthDate', 'medicationName', 'medicationQty', 'programName', 'dosageText', 'doctorName', 'facilityName', 'startDate', 'endDate', 'otpInfo'))->render();
    }
}
