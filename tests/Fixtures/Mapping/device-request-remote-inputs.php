<?php

declare(strict_types=1);

$cases = require __DIR__.'/service-request-remote-inputs.php';
$cases['device_definition'] = ['id' => 'device-id', 'code_reference' => ['identifier' => ['value' => 'definition-id']]];
$cases['classification'] = ['codeCodeableConcept' => ['coding' => [['code' => 'classification-code']]]];

return $cases;
