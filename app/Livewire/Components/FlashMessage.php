<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Livewire\Attributes\Locked;

/** Compatibility name for existing integrations; rendering lives in XMessage. */
class FlashMessage extends XMessage
{
    #[Locked]
    public bool $listenAsync = true;
}
