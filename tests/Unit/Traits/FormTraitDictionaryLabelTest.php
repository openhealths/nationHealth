<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\FormTrait;
use Tests\TestCase;

class FormTraitDictionaryLabelTest extends TestCase
{
    public function test_dictionary_label_by_code_returns_fallback_for_empty_code(): void
    {
        $component = $this->makeTraitComponent();

        $this->assertSame('', $component->dictionaryLabelByCode('SPECIALITY_TYPE', ''));
        $this->assertSame('—', $component->dictionaryLabelByCode('SPECIALITY_TYPE', null, '—'));
    }

    public function test_dictionary_label_by_code_returns_dictionary_value(): void
    {
        $component = $this->makeTraitComponent();

        $this->assertSame(
            'Терапія',
            $component->dictionaryLabelByCode('SPECIALITY_TYPE', 'THERAPY')
        );
    }

    private function makeTraitComponent(): object
    {
        return new class
        {
            use FormTrait;

            public function __construct()
            {
                $this->dictionaries = [
                    'SPECIALITY_TYPE' => [
                        'THERAPY' => 'Терапія',
                    ],
                ];
            }
        };
    }
}
