<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mapping\EHealth\Referral\MapServiceRequestBody;
use App\Mapping\Transforms\FhirIdentifier;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class ObjectMapperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laravel can autowire an unbound class, but PSR-11 has() only reports registered services.
        $this->app->bind(FhirIdentifier::class, FhirIdentifier::class);
        $this->app->bind(MapServiceRequestBody::class, MapServiceRequestBody::class);

        $this->app->bind(ObjectMapperInterface::class, static fn (Application $app): ObjectMapper => new ObjectMapper(
            propertyAccessor: PropertyAccess::createPropertyAccessor(),
            transformCallableLocator: $app,
            conditionCallableLocator: $app,
        ));
    }
}
