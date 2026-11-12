<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Support\CarePlanWorkflowHarness;

class CarePlanResourceHarness extends CarePlanWorkflowHarness
{
    public function toFhir(array $data, mixed ...$context): array
    {
        return $this->carePlanResourceFromFields($data, ...$context);
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        return $this->carePlanFieldsFromResource($data, ...$context);
    }
}
