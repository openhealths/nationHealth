<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CarePlanTv39ViewCompilationTest extends TestCase
{
    public function test_changed_care_plan_views_compile_to_valid_php(): void
    {
        foreach ([
            'livewire/care-plan/care-plan-index.blade.php',
            'livewire/care-plan/care-plan-show.blade.php',
            'livewire/care-plan/parts/additional_info.blade.php',
            'livewire/care-plan/parts/modals/medical-device-form-drawer.blade.php',
            'livewire/person/records/care-plans.blade.php',
        ] as $path) {
            $compiled = Blade::compileString(file_get_contents(resource_path('views/'.$path)));
            $this->assertNotEmpty(token_get_all($compiled, TOKEN_PARSE), $path);
        }
    }
}
