<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\JobStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DiagnosticReportPolicy
{
    /**
     * Determine whether the user can view the diagnostic report of the patient: one of the reports the user may read,
     * or the patient's report with an approval on it, whichever legal entity manages it.
     */
    public function view(User $user, DiagnosticReport $diagnosticReport, Person|Preperson $patient): Response
    {
        if ($user->cannot('diagnostic_report:read')) {
            return Response::denyWithStatus(404);
        }

        $hasPatientAccess = Declaration::accessGrantedTo($user, $patient, legalEntity())->exists()
            || Approval::accessGrantedTo($user, $patient, legalEntity())->exists();

        $isReadable = DiagnosticReport::readableFor($patient, $hasPatientAccess)->whereKey($diagnosticReport->id)->exists()
            || (Approval::resourceAccessGrantedTo($user, $diagnosticReport, legalEntity())->exists()
                && DiagnosticReport::forPatient($patient)->whereKey($diagnosticReport->id)->exists());

        return $isReadable ? Response::allow() : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can create diagnostic report.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('diagnostic_report:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can change the diagnostic report and sign it: a draft that is not waiting for eHealth.
     */
    public function update(User $user, DiagnosticReport $diagnosticReport): Response
    {
        if ($user->cannot('diagnostic_report:write')) {
            return Response::denyWithStatus(404);
        }

        if ($diagnosticReport->status !== DiagnosticReportStatus::DRAFT
            || $diagnosticReport->ehealthLinks()->latest('id')->value('status') === JobStatus::PENDING->value) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can cancel diagnostic report.
     */
    public function cancel(User $user, DiagnosticReport $diagnosticReport): Response
    {
        if ($user->cannot('diagnostic_report:cancel')) {
            return Response::denyWithStatus(404);
        }

        if ($diagnosticReport->managingOrganization?->value !== legalEntity()?->uuid) {
            return Response::denyWithStatus(404);
        }

        if ($diagnosticReport->status !== DiagnosticReportStatus::FINAL) {
            return Response::denyWithStatus(404);
        }

        if ($diagnosticReport->encounter_id !== null) {
            return Response::denyWithStatus(404);
        }

        // A medical administrator cancels what the employees of their legal entity recorded, the patient's
        // approval not being needed for it
        $isMedicalAdministrator = Employee::wherePartyId($user->partyId)
            ->whereLegalEntityId(legalEntity()->id)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->whereEmployeeType(Role::MED_ADMIN->value)
            ->exists();

        if ($isMedicalAdministrator) {
            return Response::allow();
        }

        $currentEmployeeUuid = $user->getDiagnosticReportWriterEmployee()?->uuid;

        if (!$currentEmployeeUuid || $diagnosticReport->recordedBy?->value !== $currentEmployeeUuid) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
