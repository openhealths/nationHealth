<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Employee\Employee;
use App\Models\Relations\Speciality;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeDisplayLabelsTest extends TestCase
{
    public static function dictionaryPrefixes(): array
    {
        return [[''], ['eHealth/']];
    }

    #[DataProvider('dictionaryPrefixes')]
    public function test_position_and_stored_specialities_use_dictionary_descriptions(string $prefix): void
    {
        app()->setLocale('uk');
        Cache::forever(BasicDictionary::KEY, [
            ['name' => $prefix.'POSITION', 'values' => [['code' => 'P6', 'description' => 'Лікар-акушер-гінеколог', 'is_active' => false]]],
            ['name' => $prefix.'SPECIALITY_TYPE', 'values' => [
                ['code' => 'FAMILY_DOCTOR', 'description' => 'Загальна практика — сімейна медицина', 'is_active' => false],
                ['code' => 'OBSTETRICS_AND_GYNECOLOGY', 'description' => 'Акушерство та гінекологія'],
            ]],
        ]);
        Cache::forever(BasicDictionary::KEY.':fresh', true);
        $employee = new Employee(['position' => 'P6', 'employee_type' => 'DOCTOR']);
        $employee->setRelation('specialities', new Collection([
            new Speciality(['speciality' => 'FAMILY_DOCTOR', 'speciality_officio' => true]),
            new Speciality(['speciality' => 'OBSTETRICS_AND_GYNECOLOGY', 'speciality_officio' => false]),
            new Speciality(['speciality' => 'UNKNOWN_CODE', 'speciality_officio' => true]),
        ]));

        $this->assertSame('Лікар-акушер-гінеколог', $employee->positionLabel());
        $this->assertSame(['Загальна практика — сімейна медицина'], $employee->specialityLabels());
        $this->assertCount(3, $employee->specialities);
    }

    public function test_additional_person_qualifications_are_not_used_as_position_labels_when_no_primary_exists(): void
    {
        $employee = new Employee();
        $employee->setRelation('specialities', new Collection([
            new Speciality(['speciality' => 'NURSING', 'speciality_officio' => false]),
        ]));

        $this->assertSame([], $employee->specialityLabels());
        $this->assertCount(1, $employee->specialities);
    }
}
