<?php

declare(strict_types=1);

namespace App\Livewire\Hooks;

use Livewire\ComponentHook;

use function Livewire\on;

/**
 * Livewire's $wire proxy falls through unknown property access to server method calls.
 * Tools (e.g. Laravel Boost browser logger) and JSON.stringify($wire) look up toJSON,
 * which becomes a Livewire RPC and throws MethodNotFoundException — often masking the
 * real client/server error. Swallow these spurious calls.
 */
class IgnoreSpuriousToJsonCalls extends ComponentHook
{
    public static function provide(): void
    {
        // Register via on('call') so this works even when the hook is registered
        // after ComponentHookRegistry::boot() (AppServiceProvider boots later).
        on('call', function ($component, $method, $params, $context, $returnEarly) {
            if ($method !== 'toJSON') {
                return;
            }

            $returnEarly([]);
        });
    }
}
