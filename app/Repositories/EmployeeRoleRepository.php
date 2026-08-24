<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Models\EmployeeRole;
use Throwable;

class EmployeeRoleRepository
{
    /**
     * Store data after successful creating in EHealth.
     *
     * @param  array  $data
     * @return EmployeeRole
     * @throws Throwable
     */
    public function store(array $data): EmployeeRole
    {
        return EmployeeRole::create($data);
    }

    /**
     * Update employee role data after deactivation.
     *
     * @param  EmployeeRole  $employeeRole
     * @param  array  $data
     * @return void
     */
    public function update(EmployeeRole $employeeRole, array $data): void
    {
        $forUpdate = Arr::only($data, ['status', 'end_date', 'ehealth_updated_at', 'ehealth_updated_by']);
        $employeeRole->update($forUpdate);
    }

    /**
     * Sync data with EHealth.
     *
     * @param  array  $items
     * @return void
     * @throws Throwable
     */
    public function sync(array $items): void
    {
        $rows = array_map(
            static fn (array $item): array => (new EmployeeRole($item))->getAttributes(),
            $items
        );

        EmployeeRole::upsert($rows, 'uuid', (new EmployeeRole())->getFillable());
    }
}
