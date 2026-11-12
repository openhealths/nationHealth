<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Support\CarePlanWorkflowHarness;

class CarePlanActivityResourceHarness extends CarePlanWorkflowHarness
{
    public function toFhir(array $data, mixed ...$context): array
    {
        return $this->carePlanActivityResourceFromFields($data, ...$context);
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        return $this->carePlanActivityFieldsFromResource($data, ...$context);
    }
}
