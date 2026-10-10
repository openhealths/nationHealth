<?php

declare(strict_types=1);

return [
 'full' => ['outbound' => ['typeCode' => 'primary_care', 'name' => 'Епізод', 'careManagerId' => 'manager'], 'inbound' => ['uuid' => 'episode', 'name' => 'Епізод', 'type' => ['code' => 'primary_care'], 'careManager' => ['identifier' => ['value' => 'manager']], 'period' => ['start' => '05.10.2026 10:00']]],
 'empty name' => ['outbound' => ['typeCode' => 'primary_care', 'name' => '', 'careManagerId' => ''], 'inbound' => []],
 'explicit null hydration' => ['outbound' => ['typeCode' => 'primary_care', 'name' => '0', 'careManagerId' => 'manager'], 'inbound' => ['name' => null, 'type' => ['code' => null], 'careManager' => ['identifier' => ['value' => null]], 'period' => ['start' => null]]],
 'period no separator' => ['outbound' => ['typeCode' => 'primary_care', 'name' => 'Епізод', 'careManagerId' => 'manager'], 'inbound' => ['period' => ['start' => '05.10.2026']]],
];
