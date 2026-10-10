<?php

declare(strict_types=1);

namespace App\Dto\Observation;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthSampledData
{
    use PreservesEhealthDocumentValues;
    #[Map(source: '[valueSampledDataOrigin]')]
    public mixed $origin;
    #[Map(source: '[valueSampledDataPeriod]')]
    public mixed $period;
    #[Map(source: '[valueSampledDataFactor]')]
    public mixed $factor;
    #[Map(source: '[valueSampledDataLowerLimit]')]
    public mixed $lowerLimit;
    #[Map(source: '[valueSampledDataUpperLimit]')]
    public mixed $upperLimit;
    #[Map(source: '[valueSampledDataDimensions]')]
    public mixed $dimensions;
    #[Map(source: '[valueSampledDataData]')]
    public mixed $data;
}
