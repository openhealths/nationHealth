<?php

declare(strict_types=1);

$base = [
    'uuid' => 'dispense-fixed', 'quantity' => '2.9', 'deviceSelectionType' => 'model',
    'deviceDefinitionId' => 'definition', 'performerId' => 'performer', 'locationId' => 'division',
    'whenHandedOverDate' => '05.10.2026', 'whenHandedOverTime' => '10:15',
];
$stored = [
    'uuid' => 'stored', 'status' => 'completed', 'basedOn' => ['identifier' => ['value' => 'request']],
    'partOf' => ['identifier' => ['value' => 'procedure']], 'performer' => ['identifier' => ['value' => 'performer'], 'displayValue' => 'Лікар'],
    'location' => ['identifier' => ['value' => 'division']], 'whenHandedOver' => '2026-10-05T07:15:00Z',
    'details' => [['quantity' => ['value' => '2.9', 'code' => 'pair'], 'device' => ['identifier' => ['value' => 'definition']]]],
    'note' => 'Примітка', 'originEpisodeId' => 'origin', 'contextEpisodeId' => 'episode',
    'performerLegalEntity' => ['displayValue' => 'Заклад'], 'ehealthInsertedAt' => '2026-10-05T07:00:00Z',
    'supportingInfo' => [9 => ['identifier' => ['type' => ['coding' => [['code' => 'condition']]], 'value' => 'condition']], 42 => ['identifier' => ['type' => ['coding' => [['code' => 'observation']]], 'value' => 'observation']]],
];
$detailsMap = ['condition' => ['ehealthInsertedAt' => '2026-10-01T09:00:00Z', 'codeCode' => 'D02'], 'observation' => ['ehealthInsertedAt' => null, 'codeCode' => '0']];
$full = $base + [
    'status' => 'in_progress', 'quantityCode' => 'pair', 'basedOnId' => 'request', 'partOfId' => 'procedure',
    'note' => "Виріб\nПримітка", 'supportingInfo' => [9 => ['uuid' => 'condition', 'type' => 'condition', 'ignored' => true], 42 => ['uuid' => 'observation', 'type' => 'observation']],
];
$type = array_replace($base, ['deviceSelectionType' => 'type', 'deviceCode' => 'classification', 'quantity' => 0]);

return [
    'minimal model' => ['outbound' => $base, 'inbound' => [], 'detailsMap' => []],
    'full sparse references' => ['outbound' => $full, 'inbound' => $stored, 'detailsMap' => $detailsMap],
    'classification with zero quantity' => ['outbound' => $type, 'inbound' => ['details' => [['quantity' => ['value' => 0], 'deviceCode' => ['coding' => [['code' => 'classification']]]]]], 'detailsMap' => []],
    'null outbound defaults and inbound nulls' => ['outbound' => $base + ['status' => null, 'quantityCode' => null, 'supportingInfo' => null], 'inbound' => ['uuid' => null, 'status' => null, 'note' => null, 'basedOn' => ['identifier' => ['value' => null]], 'details' => [['quantity' => ['value' => null, 'code' => null]]]], 'detailsMap' => []],
    'false-like optional fields omitted' => ['outbound' => $base + ['basedOnId' => '0', 'partOfId' => 0, 'note' => false, 'supportingInfo' => []], 'inbound' => ['basedOn' => ['identifier' => ['value' => '0']], 'partOf' => ['identifier' => ['value' => 0]], 'note' => false], 'detailsMap' => []],
    'required false-like values retained' => ['outbound' => array_replace($type, ['status' => '', 'quantity' => false, 'quantityCode' => '', 'performerId' => '', 'locationId' => '0', 'deviceCode' => '0']), 'inbound' => ['status' => '', 'performer' => ['identifier' => ['value' => '']], 'location' => ['identifier' => ['value' => '0']], 'details' => [['quantity' => ['value' => false, 'code' => ''], 'deviceCode' => ['coding' => [['code' => '0']]]]]], 'detailsMap' => []],
    'empty model id still selects model outbound' => ['outbound' => array_replace($base, ['deviceDefinitionId' => '']), 'inbound' => ['details' => [['device' => ['identifier' => ['value' => '']], 'deviceCode' => ['coding' => [['code' => 'fallback']]]]]], 'detailsMap' => []],
    'zero model id selects type inbound' => ['outbound' => array_replace($base, ['deviceDefinitionId' => '0']), 'inbound' => ['details' => [['device' => ['identifier' => ['value' => '0']]]]], 'detailsMap' => []],
    'integer casts and unknown selection' => ['outbound' => array_replace($type, ['quantity' => '1e2', 'deviceSelectionType' => 'other', 'quantityCode' => '0']), 'inbound' => ['details' => [['quantity' => ['value' => '1e2', 'code' => '0']]]], 'detailsMap' => []],
    'null collections and references' => ['outbound' => $base, 'inbound' => ['details' => null, 'supportingInfo' => null, 'performer' => null, 'whenHandedOver' => null, 'ehealthInsertedAt' => null], 'detailsMap' => []],
    'sparse details do not replace first detail' => ['outbound' => $type, 'inbound' => ['details' => [42 => ['quantity' => ['value' => 99], 'device' => ['identifier' => ['value' => 'unused']]]]], 'detailsMap' => []],
    'multiple details use only first' => ['outbound' => $base, 'inbound' => ['details' => [['quantity' => ['value' => 3.9]], ['quantity' => ['value' => 99], 'device' => ['identifier' => ['value' => 'unused']]]]], 'detailsMap' => []],
    'supporting metadata missing or null' => ['outbound' => $full, 'inbound' => ['supportingInfo' => [5 => [], 9 => ['identifier' => ['value' => null]], 42 => ['identifier' => ['value' => 'unknown', 'type' => ['coding' => [['code' => null]]]]]]], 'detailsMap' => ['' => ['codeCode' => 'empty-id'], 'unknown' => ['ehealthInsertedAt' => '', 'codeCode' => false]]],
    'duplicate supporting references retain order' => ['outbound' => $base + ['supportingInfo' => [8 => ['uuid' => 'same', 'type' => 'condition'], 42 => ['uuid' => 'same', 'type' => 'observation']]], 'inbound' => ['supportingInfo' => [8 => ['identifier' => ['value' => 'same', 'type' => ['coding' => [['code' => 'condition']]]]], 42 => ['identifier' => ['value' => 'same', 'type' => ['coding' => [['code' => 'observation']]]]]]], 'detailsMap' => ['same' => ['ehealthInsertedAt' => 'raw-date', 'codeCode' => 0]]],
    'DST offsets and display fields' => ['outbound' => array_replace($base, ['whenHandedOverDate' => '25.10.2026', 'whenHandedOverTime' => '04:15']), 'inbound' => ['whenHandedOver' => '2026-10-25T02:15:00Z', 'ehealthInsertedAt' => '2026-10-25T02:00:00Z', 'originEpisodeId' => null, 'contextEpisodeId' => '0', 'performerLegalEntity' => ['displayValue' => null], 'performer' => ['displayValue' => '0'] ], 'detailsMap' => []],
    'unknown fields stay outside contract' => ['outbound' => $full + ['password' => 'synthetic-secret', 'unknown' => ['x' => 1]], 'inbound' => $stored + ['id' => 'unused', 'unknown' => ['x' => 1]], 'detailsMap' => $detailsMap],
];
