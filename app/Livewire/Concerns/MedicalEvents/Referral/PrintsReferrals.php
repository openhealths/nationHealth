<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Referral;

use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Repositories\MedicalEvents\Repository;

trait PrintsReferrals
{
    protected function referralPrintoutHtml(CarePlan|\App\Models\MedicalEvents\Sql\Encounter $contextModel, string $requestId): string
    {
        $record = Repository::serviceRequest()->findByUuid($requestId)
            ?? Repository::deviceRequest()->findByUuid($requestId);

        if (!$record instanceof ServiceRequestRequest && !$record instanceof DeviceRequestRequest) {
            throw new \RuntimeException('Направлення не знайдено');
        }

        $record->loadMissing(['employee', 'employee.party']);
        if ($contextModel instanceof CarePlan) {
            $contextModel->loadMissing(['person']);
        }

        $code = $record instanceof ServiceRequestRequest ? $record->serviceId : $record->deviceId;
        $name = $record instanceof ServiceRequestRequest
            ? __('care-plan.referral_printout_type_service')
            : __('care-plan.referral_printout_type_device');
        $statusLabel = \App\Enums\Person\ServiceRequestStatus::labelFor((string) $record->status);
        $employeeName = $record->employee?->fullName ?? $record->employee?->full_name ?? '—';
        $patient = $contextModel instanceof CarePlan
            ? $contextModel->person
            : \App\Models\Person\Person::find($contextModel->person_id);
        $patientName = $patient?->fullName
            ?? ($patient?->primaryName ? trim($patient->primaryName->last_name.' '.$patient->primaryName->first_name) : '—');
        $adviceText = $record instanceof ServiceRequestRequest
            ? 'Зверніться до будь-якого медичного закладу, що надає відповідні послуги за контрактом з НСЗУ.'
            : 'Зверніться до аптеки або закладу, що бере участь у програмі реімбурсації чи відпуску відповідних медичних виробів за контрактом з НСЗУ.';

        $requisition = (string) ($record->requestNumber ?: $record->uuid);
        $barcodeHtml = $this->buildCode128BarcodeHtml($requisition);

        return view('livewire.referral.printout', [
            'requisition' => $requisition, 'barcodeHtml' => $barcodeHtml, 'name' => $name,
            'statusLabel' => $statusLabel, 'patientName' => $patientName, 'code' => $code,
            'quantity' => (string) $record->quantity, 'startedAt' => $record->startedAt,
            'endedAt' => $record->endedAt, 'employeeName' => $employeeName,
            'note' => (string) $record->note, 'adviceText' => $adviceText,
        ])->render();
    }

    protected function buildCode128BarcodeHtml(string $requisition): string
    {
        $value = trim($requisition);
        if ($value === '') {
            return '';
        }

        try {
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
            $binary = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 60);

            return '<img alt="CODE128 '.e($value).'" src="data:image/png;base64,'.base64_encode($binary).'" style="max-width:100%;height:auto;" />'
                .'<div style="font-family:monospace;font-size:12px;margin-top:4px;">'.e($value).'</div>';
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Failed to render CODE128 barcode for referral printout: '.$exception->getMessage());

            return '<div style="font-family:monospace;font-size:14px;">'.e($value).'</div>';
        }
    }
}
