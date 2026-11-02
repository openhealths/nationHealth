<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Dictionary\Dictionaries\BasicDictionary;
use Illuminate\Support\Facades\Cache;

/**
 * Seed the basic eHealth dictionary cache so Livewire employee/party/healthcare service screens
 * can render without calling the (currently dead) preprod dictionary API.
 */
trait MocksBasicDictionaries
{
    /**
     * @param  array<string, array<string, string>>  $extra  Extra name => [code => description]
     */
    protected function mockBasicDictionaries(array $extra = []): void
    {
        $maps = array_replace([
            'PHONE_TYPE' => ['MOBILE' => 'Мобільний', 'LAND_LINE' => 'Стаціонарний'],
            'COUNTRY' => ['UA' => 'Україна'],
            'SETTLEMENT_TYPE' => ['CITY' => 'Місто', 'VILLAGE' => 'Село'],
            'SPECIALITY_TYPE' => ['FAMILY_DOCTOR' => 'Сімейний лікар', 'THERAPIST' => 'Терапевт'],
            'DIVISION_TYPE' => ['CLINIC' => 'Клініка'],
            'HEALTHCARE_SERVICE_CATEGORIES' => ['MSP' => 'Медичні послуги', 'PHARMACY_DRUGS' => 'Відпуск лікарських засобів'],
            'PROVIDING_CONDITION' => ['OUTPATIENT' => 'Амбулаторні умови'],
            'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES' => ['SALE' => 'Продаж'],
            'SPECIALITY_LEVEL' => ['FIRST' => 'Перша'],
            'GENDER' => ['MALE' => 'Чоловік', 'FEMALE' => 'Жінка'],
            'AUTHENTICATION_METHOD' => [
                'OTP' => 'Через СМС',
                'OFFLINE' => 'Через документи',
                'THIRD_PERSON' => 'Через іншу особу',
            ],
            'QUALIFICATION_TYPE' => ['SPECIALIST' => 'Спеціаліст'],
            'SCIENCE_DEGREE' => ['CANDIDATE_OF_SCIENCE' => 'Кандидат наук'],
            'DOCUMENT_TYPE' => [
                'PASSPORT' => 'Паспорт',
                'NATIONAL_ID' => 'ID-картка',
                'BIRTH_CERTIFICATE' => 'Свідоцтво про народження',
            ],
            'SPEC_QUALIFICATION_TYPE' => ['SPECIALIST' => 'Спеціаліст'],
            'EMPLOYEE_TYPE' => [
                'DOCTOR' => 'Лікар',
                'SPECIALIST' => 'Спеціаліст',
                'ASSISTANT' => 'Асистент',
                'HR' => 'Кадри',
                'ADMIN' => 'Адмін',
                'OWNER' => 'Власник',
            ],
            'POSITION' => ['P10' => 'Лікар', 'Doctor' => 'Лікар', 'P1' => 'Лікар'],
            'EDUCATION_DEGREE' => ['MASTER' => 'Магістр'],
        ], $extra);

        $payload = [];
        foreach ($maps as $name => $codes) {
            $payload[] = [
                'name' => $name,
                'values' => collect($codes)
                    ->map(static fn (string $description, string $code): array => [
                        'code' => $code,
                        'description' => $description,
                        'is_active' => true,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        Cache::put(BasicDictionary::KEY, $payload, now()->addDay());
        Cache::put(BasicDictionary::KEY.':fresh', true, now()->addDay());
    }
}
