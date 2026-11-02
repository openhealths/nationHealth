<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Dictionary\Collections\EmployeeDictionaryCollection;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Support\Facades\Http;
use Mockery;

trait FakesEmployeeDictionaries
{
    protected function fakeEmployeeDictionaries(array $dictionaries = []): EmployeeDictionaryCollection
    {
        Http::preventStrayRequests();
        $values = [];

        foreach (EmployeeDictionaryCollection::ROLE_DICTIONARIES as $name => $configKey) {
            $codes = [];
            foreach (config('ehealth.employee_type') as $settings) {
                $codes = array_merge($codes, $settings[$configKey] ?? []);
            }
            $values[$name] = array_fill_keys(array_unique($codes), 'Label');
        }

        $values += [
            'EMPLOYEE_TYPE' => array_fill_keys(array_keys(config('ehealth.employee_type')), 'Role'),
            'SCIENCE_DEGREE' => ['PHD' => 'PhD'],
            'PHONE_TYPE' => ['MOBILE' => 'Мобільний'],
            'GENDER' => ['MALE' => 'Чоловіча'],
            'COUNTRY' => ['UA' => 'Україна'],
            'DOCUMENT_TYPE' => ['PASSPORT' => 'Паспорт', 'NATIONAL_ID' => 'ID-картка'],
            'SETTLEMENT_TYPE' => ['CITY' => 'Місто'],
            'DIVISION_TYPE' => ['AMB' => 'Амбулаторія'],
        ];
        $items = [];
        foreach (array_replace($values, $dictionaries) as $name => $entries) {
            $items[] = ['name' => $name, 'values' => collect($entries)->map(
                fn ($label, $code) => ['code' => $code, 'description' => $label, 'is_active' => true]
            )->values()->all()];
        }
        $catalogue = new EmployeeDictionaryCollection($items);
        $manager = Mockery::mock(DictionaryManager::class)->makePartial();
        $manager->shouldReceive('employees')->andReturn($catalogue);
        $manager->shouldReceive('basics')->andReturn($catalogue);
        $this->instance(DictionaryManager::class, $manager);

        return $catalogue;
    }
}
