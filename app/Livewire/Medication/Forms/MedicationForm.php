<?php

namespace App\Livewire\Medication\Forms;

use DateTime;

class MedicationForm
{
    public string $medication = '';

    public float $medicationQty = 0;

    public DateTime $startedAt;

    public DateTime $endedAt;
}