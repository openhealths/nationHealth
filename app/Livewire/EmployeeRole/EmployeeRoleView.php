<?php

declare(strict_types=1);

namespace App\Livewire\EmployeeRole;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\EmployeeRole;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class EmployeeRoleView extends Component
{
    use FormTrait;

    /**
     * ID of the employee role being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $employeeRoleId;

    /**
     * eHealth ID of the employee role, kept so that a refresh does not have to read the record to find it.
     *
     * @var string
     */
    #[Locked]
    public string $employeeRoleUuid;

    /**
     * Request-scoped memoized employee role.
     *
     * @var EmployeeRole|null
     */
    private ?EmployeeRole $employeeRoleModel = null;

    protected $dictionaryNames = ['SPECIALITY_TYPE'];

    /**
     * Load the dictionaries and remember the employee role being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  EmployeeRole  $employeeRole
     * @return void
     */
    public function mount(LegalEntity $legalEntity, EmployeeRole $employeeRole): void
    {
        $this->getDictionary();

        $this->employeeRoleId = $employeeRole->id;
        $this->employeeRoleUuid = $employeeRole->uuid;
    }

    /**
     * Refresh the employee role from eHealth, so that the page shows the record as it stands there now.
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::employeeRole()->getById($this->employeeRoleUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing the employee role');

            return;
        }

        try {
            Repository::employeeRole()->update($this->employeeRole(), $response->validate());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the employee role');

            return;
        }

        // Drop the memoized model so that the page renders what has just been stored
        $this->employeeRoleModel = null;

        Session::flash('success', __('employee-roles.success.synced'));
    }

    /**
     * Resolve the employee role being displayed. Loaded again on later requests, where Livewire hydrates without
     * mount().
     *
     * @return EmployeeRole
     */
    protected function employeeRole(): EmployeeRole
    {
        return $this->employeeRoleModel ??= EmployeeRole::with([
            'employee.party',
            'healthcareService.division',
            'insertedByUser.party',
            'updatedByUser.party'
        ])
            ->findOrFail($this->employeeRoleId);
    }

    public function render(): View
    {
        return view('livewire.employee-role.employee-role-view')->with(['employeeRole' => $this->employeeRole()]);
    }
}
