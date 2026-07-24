<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Log;

class LogLockout
{
    public function handle(Lockout $event): void
    {
        $componentData = request()->input('components.0.snapshot');
        $data = json_decode($componentData, true);

        $email = $data['data']['email'] ?? 'unknown';

        Log::warning(__('auth.login.error.lockout', [], 'en'), [
            'ip' => $event->request->ip(),
            'email' => $email
        ]);
    }
}
