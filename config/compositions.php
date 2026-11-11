<?php

declare(strict_types=1);

return [
    // An explicit environment-specific mirror of the legacy Composition service's EMAL chart parameters.
    // The official contract lists no MIS endpoint that supplies them; never guess deployment values.
    'emal' => env('EHEALTH_COMPOSITION_EMAL_CONFIG'),
];
