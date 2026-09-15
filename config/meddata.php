<?php

declare(strict_types=1);

return [
    'api' => [
        'domain' => env('MEDDATA_API_URL', 'https://preprod-api.medzakupivli.com'),
        'token' => env('MEDDATA_ACCESS_TOKEN')
    ]
];
