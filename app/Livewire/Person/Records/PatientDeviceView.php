<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\View\View;

class PatientDeviceView extends BasePatientComponent
{
    public string $deviceId;

    public array $device = [];

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, string $deviceId = ''): void
    {
        parent::mount($legalEntity, $person, $preperson);
        $this->deviceId = $deviceId;

        $this->device = [
            'id' => $this->deviceId,
            'name' => 'Кардіостимулятор Medtronic Azure XT',
            'status' => 'Активний',
            'encounter_id' => '1231-adsadas-aqeqe-casdda',
            'device_id' => '1231-adsadas-aqeqe-casdda',
            'type' => 'Дилятаційні катетери',
            'model' => '1231FDSE',
            'model_ref' => 'Medtronic Azure XT DR MRI',
            'manufacturer' => 'GlobalMed, Inc',
            'serial_number' => 'NSPX30',
            'lot_number' => 'RZ12345678',
            'manufacture_date' => '02.04.2025',
            'expiration_date' => '02.04.2025',
            'external_id' => 'OPA-12345678',
            'parent_device' => 'Батьківський виріб',
            'additional_property' => 'Додаткова властивість',
            'practitioner' => 'Шевченко Т.Г.',
            'source_type' => 'Інше джерело',
            'source_ref' => 'Запис в медичній документації',
            'created_at' => '02.04.2025',
            'created_time' => '12:00',
            'updated_at' => '02.04.2025',
            'updated_time' => '12:00',
            'notes' => 'примітки',
            'error_reason' => 'Причина',
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.device-view');
    }
}
