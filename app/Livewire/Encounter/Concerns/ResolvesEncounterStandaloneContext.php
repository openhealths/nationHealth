<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Concerns;

use App\Models\MedicalEvents\Sql\Encounter;
use Illuminate\Support\Facades\Session;

trait ResolvesEncounterStandaloneContext
{
    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        if (!isset($this->encounterId)) {
            Session::flash('error', 'Взаємодію не знайдено.');

            return null;
        }

        $encounter = Encounter::query()->with('episode')->find($this->encounterId);
        if ($encounter === null) {
            Session::flash('error', 'Взаємодію не знайдено.');

            return null;
        }

        return $encounter;
    }
}
