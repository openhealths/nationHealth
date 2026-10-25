<?php

declare(strict_types=1);

$base = ['uuid' => 'device-fixed', 'primarySource' => true, 'typeCode' => 'device-type'];
$full = $base + [
    'status' => 'inactive', 'modelNumber' => 'MODEL', 'lotNumber' => 'LOT', 'manufacturer' => 'Виробник',
    'serialNumber' => 'SERIAL', 'manufactureDate' => '2026-10-05T10:15:00+03:00',
    'expirationDate' => '2026-10-25T04:15:00+02:00', 'note' => "Пристрій\nПримітка",
    'definitionId' => 'definition', 'parentId' => 'parent',
    'names' => [8 => ['type' => 'user-friendly-name', 'value' => 'Назва', 'ignored' => 'secret'], 42 => ['type' => 'manufacturer-name', 'value' => 'Brand']],
    'identifiers' => [8 => ['code' => 'system', 'text' => 'Система', 'value' => 'external-id']],
    'properties' => [9 => ['code' => 'power', 'valueBoolean' => false, 'valueInteger' => 0, 'valueString' => '']],
];
$inbound = [
    'uuid' => 'stored', 'status' => 'inactive', 'type' => ['coding' => [['code' => 'device-type']]],
    'modelNumber' => 'MODEL', 'lotNumber' => 'LOT', 'manufacturer' => 'Виробник', 'serialNumber' => 'SERIAL',
    'manufactureDate' => '2026-10-05T07:15:00Z', 'expirationDate' => '2026-10-25T02:15:00Z',
    'note' => 'Примітка', 'primarySource' => true,
    'definition' => ['identifier' => ['value' => 'definition']], 'parent' => ['identifier' => ['value' => 'parent']],
    'names' => [8 => ['type' => 'user-friendly-name', 'value' => 'Назва'], 42 => ['type' => 'manufacturer-name', 'value' => 'Brand']],
    'identifiers' => [8 => ['identifier' => ['type' => ['coding' => [['code' => 'system']], 'text' => 'Система'], 'value' => 'external-id']]],
    'properties' => [9 => ['code' => ['coding' => [['code' => 'power']]], 'value' => ['valueBoolean' => false, 'valueInteger' => 0, 'valueString' => '']]],
];
$quantity = ['code' => 'quantity', 'valueQuantityValue' => 0, 'valueQuantityUnit' => 'g'];
$range = ['code' => 'range', 'valueRangeLowValue' => 0, 'valueRangeLowUnit' => 'g', 'valueRangeHighValue' => 10, 'valueRangeHighUnit' => 'g'];
$concept = ['code' => 'concept', 'valueCodeableConceptCode' => '0', 'valueCodeableConceptSystem' => 'dictionary_with_underscore'];

return [
    'full sparse lists' => ['outbound' => $full, 'inbound' => $inbound],
    'minimal' => ['outbound' => $base, 'inbound' => []],
    'secondary source' => ['outbound' => array_replace($full, ['primarySource' => false, 'reportOriginCode' => 'patient', 'reportOriginText' => 'Пацієнт']), 'inbound' => array_replace($inbound, ['primarySource' => false, 'reportOrigin' => ['coding' => [['code' => 'patient']], 'text' => 'Пацієнт']])],
    'null status falls back only outbound' => ['outbound' => $base + ['status' => null], 'inbound' => ['uuid' => null, 'id' => 'unused-id', 'status' => null, 'primarySource' => null, 'note' => null]],
    'required false and zero values' => ['outbound' => array_replace($base, ['status' => '0', 'primarySource' => 0, 'reportOriginCode' => '0', 'reportOriginText' => '0', 'names' => [7 => ['type' => null, 'value' => false]]]), 'inbound' => ['status' => '0', 'primarySource' => 0, 'names' => [7 => ['type' => null, 'value' => false]]]],
    'null primary source' => ['outbound' => array_replace($base, ['primarySource' => null, 'reportOriginCode' => 'patient']), 'inbound' => ['primarySource' => null]],
    'zero and empty optional fields omitted' => ['outbound' => $base + ['modelNumber' => '0', 'lotNumber' => 0, 'manufacturer' => '', 'serialNumber' => null, 'manufactureDate' => '0', 'expirationDate' => '', 'note' => false, 'definitionId' => '0', 'parentId' => null], 'inbound' => ['modelNumber' => '0', 'note' => false, 'parent' => ['identifier' => ['value' => null]]]],
    'all identifiers filtered' => ['outbound' => $base + ['identifiers' => [8 => ['value' => '0'], 21 => ['value' => null], 42 => ['value' => ''], 78 => ['value' => false]]], 'inbound' => ['identifiers' => [8 => [], 42 => ['identifier' => ['value' => null, 'type' => ['text' => null, 'coding' => [['code' => null]]]]]]]],
    'null collections' => ['outbound' => $base + ['names' => null, 'identifiers' => null, 'properties' => null], 'inbound' => ['names' => null, 'identifiers' => null, 'properties' => null]],
    'nullable property metadata' => ['outbound' => $base + ['properties' => [9 => $quantity, 42 => $range]], 'inbound' => ['properties' => [9 => ['code' => ['coding' => [['code' => 'quantity']]], 'value' => ['valueQuantity' => ['value' => 0, 'unit' => 'g', 'system' => null]]], 42 => ['value' => ['valueRange' => ['low' => ['value' => 0, 'unit' => 'g'], 'high' => ['value' => 10, 'unit' => 'g']]]]]]],
    'complete quantity range and concept' => ['outbound' => $base + ['properties' => [3 => $quantity + ['valueQuantityComparator' => '<', 'valueQuantitySystem' => 'ucum', 'valueQuantityCode' => 'g'], 8 => $range + ['valueRangeLowSystem' => 'ucum', 'valueRangeLowCode' => 'g', 'valueRangeHighSystem' => 'ucum', 'valueRangeHighCode' => 'g'], 42 => $concept]], 'inbound' => ['properties' => [42 => ['code' => ['coding' => [['code' => 'concept']]], 'value' => ['valueCodeableConcept' => ['coding' => [['system' => 'dictionary_with_underscore', 'code' => '0']]]]]]]],
    'null variant triggers omitted' => ['outbound' => $base + ['properties' => [['code' => 'empty', 'valueBoolean' => null, 'valueInteger' => null, 'valueString' => null, 'valueQuantityValue' => null, 'valueRangeLowValue' => null, 'valueCodeableConceptCode' => null]]], 'inbound' => ['properties' => [['code' => ['coding' => [['code' => null]]], 'value' => null]]]],
    'individual scalar property variants' => ['outbound' => $base + ['properties' => [4 => ['code' => 'boolean', 'valueBoolean' => false], 8 => ['code' => 'integer', 'valueInteger' => 0], 42 => ['code' => 'string', 'valueString' => '']]], 'inbound' => ['properties' => [4 => ['value' => ['valueBoolean' => false]], 8 => ['value' => ['valueInteger' => 0]], 42 => ['value' => ['valueString' => '']]]]],
    'all property variants remain ordered' => ['outbound' => $base + ['properties' => [$quantity + $range + $concept + ['valueBoolean' => false, 'valueInteger' => 0, 'valueString' => '']]], 'inbound' => $inbound],
    'unknown fields excluded' => ['outbound' => $full + ['password' => 'secret', 'unknown' => ['x' => 1]], 'inbound' => $inbound + ['unknown' => ['x' => 1]]],
    'missing and null nested form fields' => ['outbound' => $base + ['identifiers' => [['code' => 'system', 'text' => null, 'value' => 'external']]], 'inbound' => ['type' => ['coding' => [['code' => null]]], 'names' => [9 => [], 42 => ['type' => null, 'value' => null]], 'definition' => ['identifier' => ['value' => null]], 'reportOrigin' => ['text' => null, 'coding' => [['code' => null]]]]],
];
