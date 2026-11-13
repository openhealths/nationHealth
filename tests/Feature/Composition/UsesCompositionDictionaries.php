<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionType;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\SignatureService;
use Illuminate\Support\Facades\Cache;
use Mockery;

trait UsesCompositionDictionaries
{
    protected function setUpUsesCompositionDictionaries(): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        $this->withoutVite();

        $definitions = [
            'POSITION' => ['P8' => 'Лікар-педіатр', 'P6' => 'Лікар-акушер-гінеколог'],
            'eHealth/encounter_classes' => ['AMB' => 'Амбулаторний'],
            'eHealth/encounter_types' => ['AMB' => 'Амбулаторний'],
            'GENDER' => ['MALE' => 'Чоловіча', 'FEMALE' => 'Жіноча', 'UNKNOWN' => 'Невідома'],
            CompositionCategory::DICTIONARY => array_column(CompositionCategory::cases(), 'value', 'value'),
            'COMPOSITION_TREATMENT_VIOLATION' => [
                'reject_hospitalization' => 'відмова від госпіталізації',
                'reject_recommendation' => 'невиконання рекомендацій лікаря',
            ],
        ];
        foreach (CompositionType::cases() as $type) {
            $definitions[$type->cancellationReasonDictionary()] = ['incorrect_data' => 'Помилкові дані'];
        }

        $dictionaries = [];
        foreach ($definitions as $name => $options) {
            $values = [];
            foreach ($options as $code => $description) {
                $values[] = ['code' => $code, 'description' => $description, 'is_active' => true];
            }
            $dictionaries[] = ['name' => $name, 'values' => $values];
        }
        Cache::forever(BasicDictionary::KEY, $dictionaries);
        Cache::forever(BasicDictionary::KEY . ':fresh', true);

        $signer = Mockery::mock(SignatureService::class);
        $signer->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $this->app->instance(SignatureService::class, $signer);
    }
}
