<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\Relations\Party;
use App\Repositories\EmployeeRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePartyMergeTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function two_new_employees_without_party_uuid_stay_separate_people(): void
    {
        $repository = new EmployeeRepository();

        $dasha = $this->employee();
        $repository->updateDetails($dasha, [
            'first_name' => 'Даша',
            'last_name' => 'Іванова',
            'second_name' => 'Василівна',
            'birth_date' => '1990-02-01',
            'gender' => 'FEMALE',
            'no_tax_id' => true,
        ], [], []);

        $olena = $this->employee();
        $repository->updateDetails($olena, [
            'first_name' => 'Олена',
            'last_name' => 'Іванова',
            'birth_date' => '1992-03-03',
            'gender' => 'FEMALE',
            'tax_id' => '1234567890',
            'no_tax_id' => false,
        ], [], []);

        $dasha->refresh();
        $olena->refresh();

        $this->assertNotNull($dasha->partyId);
        $this->assertNotSame($dasha->partyId, $olena->partyId);
        $this->assertSame('Даша', $dasha->party->firstName);
        $this->assertSame('Олена', $olena->party->firstName);
    }

    #[Test]
    public function employee_relinks_when_a_real_party_uuid_already_exists(): void
    {
        $partyA = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Даша',
            'last_name' => 'Іванова',
            'birth_date' => '1990-02-01',
            'gender' => 'FEMALE',
        ]);
        $partyB = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Стара',
            'last_name' => 'Іванова',
            'birth_date' => '1991-02-01',
            'gender' => 'FEMALE',
        ]);

        $employee = $this->employee();
        $employee->partyId = $partyA->id;
        $employee->save();

        (new EmployeeRepository())->updateDetails($employee, [
            'uuid' => $partyB->uuid,
            'first_name' => 'Олена',
            'last_name' => 'Іванова',
            'birth_date' => '1991-02-01',
            'gender' => 'FEMALE',
        ], [], []);

        $employee->refresh();
        $partyA->refresh();

        $this->assertSame($partyB->id, $employee->partyId);
        $this->assertSame($partyA->uuid, $partyA->fresh()->uuid);
        $this->assertSame('Даша', $partyA->firstName);
        $this->assertSame('Олена', $employee->party->firstName);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'is_active' => true,
            'position' => 'P8',
            'start_date' => '2024-01-01',
        ]);
    }
}
