<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class FlashMessage extends Component
{
    #[Locked]
    public string $message = '';

    #[Locked]
    public string $type = 'success';

    #[Locked]
    public array $errors = [];

    public function mount(): void
    {
        if (session()->has('success')) {
            $this->message = (string) session('success');
            $this->type = 'success';

            return;
        }

        if (session()->has('error')) {
            $this->message = (string) session('error');
            $this->type = 'error';
        }
    }

    #[On('flashMessage')]
    public function flashMessage($flash): void
    {
        $this->message = $flash['message'] ?? '';
        $this->type = $flash['type'];
        $this->errors = $flash['errors'] ?? [];
    }

    public function render(): View
    {
        return view('livewire.components.flash-message');
    }
}
