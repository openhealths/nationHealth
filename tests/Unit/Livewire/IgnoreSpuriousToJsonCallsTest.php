<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire;

use App\Livewire\Components\FlashMessage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IgnoreSpuriousToJsonCallsTest extends TestCase
{
    #[Test]
    public function to_json_livewire_rpc_is_swallowed_instead_of_method_not_found(): void
    {
        Livewire::test(FlashMessage::class)
            ->call('toJSON')
            ->assertSuccessful();
    }

    #[Test]
    public function app_js_filters_to_json_from_livewire_commit_calls(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertNotFalse($appJs);
        $this->assertStringContainsString("call.method !== 'toJSON'", $appJs);
        $this->assertStringContainsString("Livewire.hook('commit'", $appJs);
    }
}
