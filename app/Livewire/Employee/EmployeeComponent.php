<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use App\Classes\eHealth\EHealth;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Enums\Status;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use App\Livewire\Employee\Forms\EmployeeForm as Form;
use Log;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

abstract class EmployeeComponent extends Component
{
    use FormTrait {
        getDictionary as traitGetDictionary;
    }

    public Form $form;
    public bool $isPersonalDataLocked = false;
    public bool $isPositionDataLocked = false;

    // Locks only IMMUTABLE fields (Position, Type, StartDate)
    // Allows editing: Division
    public bool $isCorePositionDataLocked = false;
    #[Locked]
    public ?int $employeeRequestId = null;
    public array $divisions = [];
    public bool $showSignatureModal = false;

    public ?array $dictionaryNames = [
        'PHONE_TYPE', 'COUNTRY', 'SETTLEMENT_TYPE', 'SPECIALITY_TYPE', 'DIVISION_TYPE',
        'SPECIALITY_LEVEL', 'GENDER', 'QUALIFICATION_TYPE', 'SCIENCE_DEGREE', 'DOCUMENT_TYPE',
        'SPEC_QUALIFICATION_TYPE', 'EMPLOYEE_TYPE', 'POSITION', 'EDUCATION_DEGREE'
    ];

    public ?array $dictionaries = [];
    public array $employeeTypePosition = [];
    public array $employeeTypeSpecialities = [];
    public array $employeeTypeLevels = [];
    public array $employeeTypeDegrees = [];
    public array $employeeTypeQualifications = [];
    public array $employeeTypeSpecQualifications = [];

    /**
     * This is the single, public method that child components will call.
     */
    public function loadDictionaries(): void
    {
        $this->getDictionary();
    }

    /**
     * The protected getDictionary method contains the implementation.
     */
    protected function getDictionary(): void
    {
        $this->traitGetDictionary();

        if (legalEntity()) {
            $allowedEmployeeTypes = config('ehealth.legal_entity_employee_types.' . legalEntity()->type->name, []);

            $this->dictionaries['EMPLOYEE_TYPE'] = array_intersect_key(
                $this->dictionaries['EMPLOYEE_TYPE'] ?? [],
                array_flip($allowedEmployeeTypes)
            );

            foreach ($this->dictionaries['EMPLOYEE_TYPE'] as $employeeType => $description) {

                $allowedQualKeys = config("ehealth.employee_type.{$employeeType}.qualification_type", []);
                $masterQualDict = $this->dictionaries['QUALIFICATION_TYPE'] ?? [];
                $this->employeeTypeQualifications[$employeeType] = array_intersect_key($masterQualDict, array_flip($allowedQualKeys));

                $allowedSpecQualKeys = config("ehealth.employee_type.{$employeeType}.speciality_qualification_type", []);
                $masterSpecQualDict = $this->dictionaries['SPEC_QUALIFICATION_TYPE'] ?? [];
                $this->employeeTypeSpecQualifications[$employeeType] = array_intersect_key($masterSpecQualDict, array_flip($allowedSpecQualKeys));

                $allowedPositionKeys = config("ehealth.employee_type.{$employeeType}.position", []);
                $masterPositionDict = $this->dictionaries['POSITION'] ?? [];
                $this->employeeTypePosition[$employeeType] = array_intersect_key($masterPositionDict, array_flip($allowedPositionKeys));

                $allowedSpecialityKeys = config("ehealth.employee_type.{$employeeType}.speciality_type", []);
                $masterSpecialityDict = $this->dictionaries['SPECIALITY_TYPE'] ?? [];
                $this->employeeTypeSpecialities[$employeeType] = array_intersect_key($masterSpecialityDict, array_flip($allowedSpecialityKeys));

                $allowedLevelKeys = config("ehealth.employee_type.{$employeeType}.speciality_level", []);
                $masterLevelDict = $this->dictionaries['SPECIALITY_LEVEL'] ?? [];
                $this->employeeTypeLevels[$employeeType] = array_intersect_key($masterLevelDict, array_flip($allowedLevelKeys));

                $allowedDegreeKeys = config("ehealth.employee_type.{$employeeType}.education_degree", []);
                $masterDegreeDict = $this->dictionaries['EDUCATION_DEGREE'] ?? [];
                $this->employeeTypeDegrees[$employeeType] = array_intersect_key($masterDegreeDict, array_flip($allowedDegreeKeys));
            }
        }
    }

    #[Computed]
    public function employeeFullName(): string
    {
        if (isset($this->employee) && $this->employee->party) {
            return $this->employee->party->fullName;
        }

        if (isset($this->party)) {
            return $this->party->fullName;
        }

        if (!empty($this->form->party['lastName'])) {
            return trim($this->form->party['lastName'] . ' ' . $this->form->party['firstName']);
        }

        return '';
    }

    protected function loadDivisions(LegalEntity $legalEntity): void
    {
        $this->divisions = $legalEntity->divisions()->where('status', Status::ACTIVE)->get(['id', 'name'])->toArray();
    }

    /**
     * Core logic to synchronize a single employee with eHealth.
     * This method is shared between Index and Show components.
     *
     * @param  Employee  $employee
     * @return bool Returns true on success, false on failure
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    protected function syncEmployeeData(Employee $employee): bool
    {
        // 1. Validation
        if (Gate::denies('sync', $employee)) {
            $this->dispatch('flashMessage', [
                'message' => 'Синхронізація недоступна для цього співробітника.',
                'type' => 'error'
            ]);

            return false;
        }

        try {
            $token = session()->get(config('ehealth.api.oauth.bearer_token'));
            if (!$token) {
                throw new \RuntimeException('Сесія eHealth не активна. Будь ласка, перезайдіть.');
            }

            // 2. API Request
            $response = EHealth::employee()
                ->withToken($token)
                ->getDetails($employee->uuid, groupByEntities: true);

            $validatedData = $response->validate();

            // 3. Database Update via Repository
            // We use app() helper to resolve the repository
            Repository::employee()->updateDetails(
                $employee,
                $validatedData['party'],
                $validatedData['documents'],
                $validatedData['phones'],
                $validatedData['educations'] ?? null,
                $validatedData['specialities'] ?? null,
                $validatedData['qualifications'] ?? null,
                $validatedData['scienceDegree'] ?? null
            );

            // 4. Close/Actualize Pending Requests
            $this->actualizePendingRequests($employee, $token);

            $this->dispatch('flashMessage', [
                'message' => 'Дані співробітника успішно оновлено з ЕСОЗ',
                'type' => 'success'
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Single Employee Sync Failed', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);

            $this->dispatch('flashMessage', [
                'message' => 'Помилка синхронізації: ' . $e->getMessage(),
                'type' => 'error'
            ]);

            return false;
        }
    }

    /**
     * Checks "hanging" requests (SIGNED) for this employee in eHealth.
     * If the request in eHealth is already APPROVED/REJECTED, updates the local status.
     *
     * @param  Employee  $employee
     * @param  string  $token
     * @return void
     */
    protected function actualizePendingRequests(Employee $employee, string $token): void
    {
        $pendingRequests = EmployeeRequest::where('employee_id', $employee->id)
            ->where('status', RequestStatus::SIGNED)
            ->whereNull('applied_at')
            ->get();

        if ($pendingRequests->isEmpty()) {
            return;
        }

        foreach ($pendingRequests as $request) {
            try {
                // Fetch specific request status from eHealth by UUID
                $response = EHealth::employeeRequest()
                    ->withToken($token)
                    ->getMany(['id' => $request->uuid]);

                $data = $response->validate();
                $remoteRequestData = $data[0] ?? null;

                if (!$remoteRequestData) {
                    continue;
                }

                $remoteStatus = $remoteRequestData['status'] ?? null;

                // Update local status based on remote status
                if ($remoteStatus === 'APPROVED') {
                    $request->update(
                        [
                            'status' => RequestStatus::APPROVED,
                            'applied_at' => now(),
                        ]
                    );
                    $request->revision?->update(['status' => RevisionStatus::APPLIED]);

                } elseif (in_array($remoteStatus, ['REJECTED', 'EXPIRED'])) {
                    $newStatus = ($remoteStatus === 'REJECTED') ? RequestStatus::REJECTED : RequestStatus::EXPIRED;
                    $request->update(
                        [
                            'status' => $newStatus,
                            'applied_at' => now(),
                        ]
                    );
                }

            } catch (\Exception $e) {
                Log::warning("Failed to check status for request {$request->uuid}: " . $e->getMessage());
                // Continue to next request without stopping the flow
            }
        }
    }
}
