<?php

declare(strict_types=1);

namespace App\Livewire\Person\Traits;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\Person\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

trait InteractsWithEmergencyContact
{
    /**
     * Medical event types eHealth accepts as the evidence for an emergency contact request.
     */
    private const array EMERGENCY_CONTACT_EVIDENCE_TYPES = ['conditions', 'diagnostic_reports'];

    public bool $showEmergencyContactModal = false;

    /**
     * Medical events the user may pick as the evidence for the request.
     *
     * @var array
     */
    public array $emergencyContactEvidences = [];

    /**
     * Emergency contact returned by eHealth.
     *
     * @var array
     */
    public array $emergencyContact = [];

    /**
     * Offer the medical events that still qualify as the evidence for an emergency contact request.
     *
     * @return void
     */
    public function openEmergencyContactModal(): void
    {
        if ($this->deniesViewingEmergencyContact()) {
            return;
        }

        $this->emergencyContact = [];
        $this->emergencyContactEvidences = $this->loadEmergencyContactEvidences();
        $this->showEmergencyContactModal = true;
    }

    /**
     * Get the patient emergency contact, backing the request with the chosen medical event.
     *
     * @param  string  $medicalEventType
     * @param  string  $medicalEventId
     * @return void
     */
    public function getEmergencyContact(string $medicalEventType, string $medicalEventId): void
    {
        if ($this->deniesViewingEmergencyContact()) {
            return;
        }

        if (!in_array($medicalEventType, self::EMERGENCY_CONTACT_EVIDENCE_TYPES, true) || !Str::isUuid($medicalEventId)) {
            Session::flash('error', __('patients.errors.emergency_contact_evidence_invalid'));

            return;
        }

        try {
            $response = EHealth::patient()->getPersonEmergencyContact($this->uuid, $medicalEventType, $medicalEventId);

            $this->emergencyContact = $response->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting the person emergency contact');
        }
    }

    /**
     * Collect the locally stored conditions and diagnostic reports of the patient that are neither entered in error
     * nor older than the period eHealth allows the emergency contact to be requested within.
     *
     * @return array
     */
    private function loadEmergencyContactEvidences(): array
    {
        $patient = $this->patient();

        // A configured value of 0 still admits the events created within the last day, hence the extra day
        $earliestInsertedAt = CarbonImmutable::now('UTC')
            ->subDays(config('ehealth.emergency_contact_medical_event_max_days_passed') + 1)
            ->format('Y-m-d H:i:s');

        $conditions = Condition::forPatient($patient)
            ->where('verification_status', '!=', ConditionVerificationStatus::ENTERED_IN_ERROR->value)
            ->where('ehealth_inserted_at', '>', $earliestInsertedAt)
            ->with('code.coding')
            ->latest('ehealth_inserted_at')
            ->get()
            ->map(static fn (Condition $condition): array => [
                'type' => 'conditions',
                'uuid' => $condition->uuid,
                'label' => __('patients.emergency_contact_request.condition'),
                'description' => $condition->codeDisplay ?? '-',
                'insertedAt' => $condition->ehealthInsertedAt
            ]);

        $diagnosticReports = DiagnosticReport::forPatient($patient)
            ->where('status', '!=', DiagnosticReportStatus::ENTERED_IN_ERROR->value)
            ->where('ehealth_inserted_at', '>', $earliestInsertedAt)
            ->latest('ehealth_inserted_at')
            ->get()
            ->map(static fn (DiagnosticReport $report): array => [
                'type' => 'diagnostic_reports',
                'uuid' => $report->uuid,
                'label' => __('patients.emergency_contact_request.diagnostic_report'),
                'description' => $report->conclusion ?? '-',
                'insertedAt' => $report->ehealthInsertedAt
            ]);

        return $conditions->concat($diagnosticReports)->values()->toArray();
    }

    /**
     * Whether the user may not read the patient emergency contact.
     *
     * @return bool
     */
    private function deniesViewingEmergencyContact(): bool
    {
        if (Auth::user()->cannot('viewEmergencyContact', Person::class)) {
            Session::flash('error', __('patients.policy.view_emergency_contact'));

            return true;
        }

        return false;
    }
}
