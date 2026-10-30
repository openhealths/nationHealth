<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class XMessage extends Component
{
    /** Only the layout listens for legacy notification events. */
    #[Locked]
    public bool $listenAsync = false;

    /** Consume rendered messages so the layout toast cannot display them again. */
    public bool $consumeMessages = true;

    #[Locked]
    public string $message = '';

    #[Locked]
    public string $type = 'success';

    #[Locked]
    public array $errors = [];

    #[Locked]
    public int $notificationId = 0;

    protected function getListeners(): array
    {
        return $this->listenAsync ? ['flashMessage' => 'flashMessage'] : [];
    }

    public function flashMessage(array $flash): void
    {
        $this->message = $flash['message'] ?? '';
        $this->type = $flash['type'] ?? 'success';
        $this->errors = $flash['errors'] ?? [];
        $this->notificationId++;
    }

    public function render(): View
    {
        $notifications = [];

        foreach (['error', 'success', 'info', 'warning', 'status'] as $type) {
            if (session()->has($type)) {
                $notifications[] = [
                    'type' => $type === 'status' ? 'success' : $type,
                    'message' => $this->consumeMessages ? session()->pull($type) : session($type),
                    'errors' => [],
                ];
            }
        }

        if ($this->message !== '' || $this->errors !== []) {
            $notifications[] = [
                'type' => $this->errors !== [] ? 'error' : $this->type,
                'message' => $this->message,
                'errors' => $this->errors,
            ];
        }

        return view('livewire.components.x-message', ['notifications' => $notifications]);
    }
}
