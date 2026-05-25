<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

class PaperReferralMapper
{
    public static function toFhir(array $data): ?array
    {
        if (empty($data['paperReferralRequesterLegalEntityEdrpou'])) {
            return null;
        }

        return [
            'requisition' => $data['paperReferralRequisition'] ?? '',
            'requesterEmployeeName' => $data['paperReferralRequesterEmployeeName'] ?? '',
            'requesterLegalEntityEdrpou' => $data['paperReferralRequesterLegalEntityEdrpou'],
            'requesterLegalEntityName' => $data['paperReferralRequesterLegalEntityName'],
            'serviceRequestDate' => convertToYmd($data['paperReferralServiceRequestDate']),
            'note' => $data['paperReferralNote'] ?? ''
        ];
    }

    public static function fromFhir(array $data): array
    {
        $hasPaperReferral = !empty(data_get($data, 'paperReferral'));
        $hasBasedOn = !empty(data_get($data, 'basedOn'));

        return [
            'isReferralAvailable' => $hasPaperReferral || $hasBasedOn,
            'referralType' => match (true) {
                $hasPaperReferral => 'paper',
                $hasBasedOn => 'electronic',
                default => ''
            },
            'paperReferralRequisition' => data_get($data, 'paperReferral.requisition', ''),
            'paperReferralRequesterEmployeeName' => data_get($data, 'paperReferral.requesterEmployeeName', ''),
            'paperReferralRequesterLegalEntityEdrpou' => data_get($data, 'paperReferral.requesterLegalEntityEdrpou', ''),
            'paperReferralRequesterLegalEntityName' => data_get($data, 'paperReferral.requesterLegalEntityName', ''),
            'paperReferralServiceRequestDate' => convertToAppDateFormat(data_get($data, 'paperReferral.serviceRequestDate', '')),
            'paperReferralNote' => data_get($data, 'paperReferral.note', '')
        ];
    }
}
