<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Concerns;

use App\Models\MedicalEvents\Sql\Encounter;

trait ResolvesEncounterStandaloneContext
{
    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        if (!isset($this->encounterId)) {
            $this->flashOutcome('error', 'Взаємодію не знайдено.');

            return null;
        }

        $encounter = Encounter::query()->with('episode')->find($this->encounterId);
        if ($encounter === null) {
            $this->flashOutcome('error', 'Взаємодію не знайдено.');

            return null;
        }

        return $encounter;
    }

    /**
     * Livewire AJAX does not remount the layout toast, so session flash alone is invisible.
     * Keep the session value for the next full page load and also push it to Alpine / x-message.
     */
    protected function flashOutcome(string $type, string $message): void
    {
        session()->flash($type, $message);

        if (method_exists($this, 'dispatch')) {
            $this->dispatch('flashMessage', ['message' => $message, 'type' => $type]);
        }
    }
}
