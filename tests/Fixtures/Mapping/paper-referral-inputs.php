<?php

declare(strict_types=1);

$base = [
    'paperReferralRequisition' => 'REQ-101',
    'paperReferralRequesterEmployeeName' => 'Лікар «А»',
    'paperReferralRequesterLegalEntityEdrpou' => '12345678',
    'paperReferralRequesterLegalEntityName' => 'Заклад',
    'paperReferralServiceRequestDate' => '05.10.2026',
    'paperReferralNote' => "Перший рядок\nДругий рядок",
];

return [
    'full paper' => ['outbound' => $base, 'inbound' => ['paperReferral' => [
        'requisition' => 'REQ-101', 'requesterEmployeeName' => 'Лікар «А»',
        'requesterLegalEntityEdrpou' => '12345678', 'requesterLegalEntityName' => 'Заклад',
        'serviceRequestDate' => '2026-10-05', 'note' => "Перший рядок\nДругий рядок",
    ]]],
    'minimal and empty date' => ['outbound' => [
        'paperReferralRequesterLegalEntityEdrpou' => '12345678', 'paperReferralServiceRequestDate' => '',
    ], 'inbound' => ['paperReferral' => ['requesterLegalEntityEdrpou' => '12345678']]],
    'explicit null fields' => ['outbound' => array_replace($base, [
        'paperReferralRequisition' => null, 'paperReferralRequesterEmployeeName' => null,
        'paperReferralRequesterLegalEntityName' => null, 'paperReferralNote' => null,
    ]), 'inbound' => ['paperReferral' => [
        'requisition' => null, 'requesterEmployeeName' => null, 'requesterLegalEntityEdrpou' => null,
        'requesterLegalEntityName' => null, 'serviceRequestDate' => null, 'note' => null,
    ]]],
    'zero-like edrpou' => ['outbound' => array_replace($base, ['paperReferralRequesterLegalEntityEdrpou' => '0']), 'inbound' => ['paperReferral' => [], 'basedOn' => ['identifier' => ['value' => 'electronic']]]],
    'missing edrpou' => ['outbound' => [], 'inbound' => []],
    'empty edrpou' => ['outbound' => array_replace($base, ['paperReferralRequesterLegalEntityEdrpou' => '']), 'inbound' => ['paperReferral' => null, 'basedOn' => []]],
    'paper wins over electronic' => ['outbound' => $base, 'inbound' => ['paperReferral' => ['note' => 'paper'], 'basedOn' => ['identifier' => ['value' => 'electronic']]]],
    'false zero and unknown fields' => ['outbound' => array_replace($base, ['paperReferralRequisition' => '0', 'paperReferralNote' => '0', 'password' => 'must not map']), 'inbound' => ['paperReferral' => ['requisition' => 0, 'note' => false, 'unknown' => ['zero' => 0]]]],
    'timestamp date' => ['outbound' => array_replace($base, ['paperReferralServiceRequestDate' => '2026-10-25T00:15:00+03:00']), 'inbound' => ['paperReferral' => ['serviceRequestDate' => '2026-10-25T00:15:00+03:00']]],
];
