<?php

declare(strict_types=1);

return [
    'process DST' => ['data' => ['receivedDate' => '25.10.2026', 'receivedTime' => '04:15', 'rejectReason' => '0', 'invalidateReason' => 'used'], 'snapshot' => ['id' => 'remote', 'unknown' => ['literal_key' => null, 'empty_list' => [], 'value' => false], 'status' => 'available', 'status_reason' => ['coding' => [['code' => 'old']]]], 'reason' => '0'],
    'empty reason and sparse raw lists' => ['data' => ['receivedDate' => '05.10.2026', 'receivedTime' => '11:00', 'rejectReason' => '', 'invalidateReason' => '0'], 'snapshot' => ['uuid' => 'remote', 'extension' => [8 => ['value' => 0], 42 => ['value' => false]], 'opaque' => (object) []], 'reason' => ''],
    'unicode reason and raw null' => ['data' => ['receivedDate' => '05.10.2026', 'receivedTime' => '11:00', 'rejectReason' => 'reason', 'invalidateReason' => 'other'], 'snapshot' => ['id' => 'remote', 'status' => null, 'status_reason' => null, 'note' => "Зразок\nПримітка"], 'reason' => 'Причина'],
];
