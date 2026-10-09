<?php

declare(strict_types=1);

namespace App\Enums\Composition;

enum CompositionRelation: string
{
    case REPLACES = 'replaces';
    case APPENDS = 'appends';
}
