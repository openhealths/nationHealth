<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Core\Arr;
use App\Enums\MedicalProgram\Type;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class MedicalDevice extends Component
{
    use FormTrait;

    /**
     * Active medical programs filtered by type and user role
     *
     * @var array
     */
    public array $activePrograms = [];

    public array $dictionaryNames = [
        'SPECIALITY_TYPE',
        'FUNDING_SOURCE'
    ];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $user = Auth::user();
        $roles = $user->allowedRoles;
        $mainSpeciality = $user->getMainSpeciality($legalEntity);
        $filteredPrograms = dictionary()->medicalPrograms()
            ->where('is_active', '=', true)
            ->where('type', '=', Type::DEVICE);

        // Filter by employee types allowed to create requests
        $filteredPrograms = $filteredPrograms->filter(function (array $program) use ($roles) {
            $allowedEmployeeTypes = Arr::get($program, 'medical_program_settings.employee_types_to_create_request', []);

            return $roles->intersect($allowedEmployeeTypes)->isNotEmpty();
        });

        // Additional filter for SPECIALIST role - check speciality_types_allowed
        if ($user->hasAllowedRole(Role::SPECIALIST->value)) {
            $filteredPrograms = $filteredPrograms->filter(function (array $program) use ($mainSpeciality) {
                $allowedSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_allowed', []);

                return $mainSpeciality->intersect($allowedSpecialities)->isNotEmpty();
            });
        }

        $this->activePrograms = $filteredPrograms->values()->toArray();
    }

    public function render(): View
    {
        return view('livewire.dictionary.medical-device');
    }
}
