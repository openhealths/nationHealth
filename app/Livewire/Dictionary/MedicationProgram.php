<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Core\Arr;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class MedicationProgram extends Component
{
    use FormTrait;

    private const string PROGRAM_TYPE = 'MEDICATION';

    /**
     * Active medical programs filtered by user role and speciality
     *
     * @var array
     */
    public array $activePrograms = [];

    public array $dictionaryNames = [
        'SPECIALITY_TYPE',
        'FUNDING_SOURCE',
        'eHealth/clinical_impression_patient_categories'
    ];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $user = Auth::user();
        $roles = $user->roles->pluck('name');
        $mainSpeciality = $user->party->employees
            ->where('legal_entity_id', $legalEntity->id)
            ->load('specialities')
            ->flatMap->specialities
            ->where('speciality_officio', true)
            ->pluck('speciality');
        $filteredPrograms = dictionary()->medicalPrograms()
            ->where('is_active', '=', true)
            ->where('type', '=', self::PROGRAM_TYPE);

        // Main speciality filter
        if ($roles->contains(Role::SPECIALIST->value) || $roles->contains(Role::DOCTOR->value)) {
            $filteredPrograms = $filteredPrograms->filter(function (array $program) use ($mainSpeciality) {
                $allowedSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_allowed', []);

                return $mainSpeciality->intersect($allowedSpecialities)->isNotEmpty();
            });
        }

        // TODO: How to check whether the doctor has that or not on our side?
        // 3.13.2.1.4 - Employee declaration filter, 3.13.2.1.5 - Legal entity declaration filter
        if ($roles->contains(Role::DOCTOR->value)) {
            // medical_program_settings.skip_request_employee_declaration_verify | medical_program_settings.skip_request_legal_entity_declaration_verify
        }

        $this->activePrograms = $filteredPrograms->values()->toArray();
    }

    public function render(): View
    {
        return view('livewire.dictionary.medication-program');
    }
}
