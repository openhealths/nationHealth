<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dictionary;

use App\Services\Dictionary\ServiceProgramPicker;
use Tests\TestCase;

class ServiceProgramPickerTest extends TestCase
{
    public function test_prefers_state_financial_guarantees_when_several_programs_exist(): void
    {
        $pmgId = 'pmg-uuid';

        $id = ServiceProgramPicker::defaultId([
            ['id' => 'other-uuid', 'name' => 'Інша програма'],
            [
                'id' => $pmgId,
                'name' => 'Програма державних фінансових гарантій медичного обслуговування населення',
            ],
        ]);

        $this->assertSame($pmgId, $id);
    }

    public function test_falls_back_to_the_first_program_when_pmg_is_missing(): void
    {
        $this->assertSame(
            'first-uuid',
            ServiceProgramPicker::defaultId([
                ['id' => 'first-uuid', 'name' => 'Перша'],
                ['id' => 'second-uuid', 'name' => 'Друга'],
            ])
        );
    }

    public function test_returns_empty_string_when_the_list_is_empty(): void
    {
        $this->assertSame('', ServiceProgramPicker::defaultId([]));
    }
}
