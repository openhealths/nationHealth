<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Models\LegalEntity;
use App\Models\Preperson;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PrepersonData extends Component
{
    /**
     * Preperson ID.
     *
     * @var int
     */
    #[Locked]
    public int $prepersonId;

    /**
     * Preperson full name.
     *
     * @var string
     */
    public string $patientFullName;

    /**
     * Preperson MPI identifier.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $uuid = null;

    /**
     * Initialize the component from the route-bound preperson.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Preperson  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, Preperson $preperson): void
    {
        $this->prepersonId = $preperson->id;
        $this->patientFullName = $preperson->fullName;
        $this->uuid = $preperson->uuid;
    }

    /**
     * Render the preperson data screen.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.preperson.preperson-data');
    }
}
