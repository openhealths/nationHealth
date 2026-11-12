<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\Person\Person;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CarePlanCreateHarness;
use Tests\TestCase;

class CarePlanPatientSearchTest extends TestCase
{
    use DatabaseTransactions;

    public static function searchNames(): array
    {
        return [
            'same case' => ['Якийсь', 'Пацієнт'],
            'lower case' => ['якийсь', 'пацієнт'],
            'upper case' => ['ЯКИЙСЬ', 'ПАЦІЄНТ'],
            'swapped' => ['пацієнт', 'якийсь'],
            'surrounding spaces' => ['  якийсь ', ' пацієнт  '],
        ];
    }

    #[DataProvider('searchNames')]
    public function test_local_patient_search_matches_names_without_case_or_space_dependency(string $firstName, string $lastName): void
    {
        app()->setLocale('uk');
        $person = $this->patient('2001-02-23');
        $this->patient('2001-02-24');

        $results = (new CarePlanCreateHarness())->searchPatients($firstName, $lastName, '2001-02-23');

        $this->assertCount(1, $results);
        $this->assertSame($person->id, $results[0]['id']);
        $this->assertSame('Пацієнт Якийсь Так', $results[0]['name']);
        $this->assertSame('23.02.2001', $results[0]['birthDate']);
    }

    public function test_patient_birth_date_still_must_match(): void
    {
        $this->patient('2001-02-23');

        $this->assertSame([], (new CarePlanCreateHarness())->searchPatients('якийсь', 'пацієнт', '2001-02-24'));
    }

    private function patient(string $birthDate): Person
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => $birthDate, 'gender' => 'MALE']);
        $person->names()->create(['first_name' => 'Якийсь', 'last_name' => 'Пацієнт', 'second_name' => 'Так', 'language' => 'uk']);

        return $person;
    }
}
