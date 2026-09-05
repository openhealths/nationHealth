<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * Action a confidant person relationship request was created with. It comes back with the request itself and
 * tells apart the two things a signed request means: a relationship that starts and one that ends.
 *
 * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/sign-confidant-person-relationship-request
 */
enum ConfidantPersonRelationshipRequestAction: string
{
    use EnumUtils;

    case INSERT = 'INSERT';
    case DEACTIVATE = 'DEACTIVATE';
}
