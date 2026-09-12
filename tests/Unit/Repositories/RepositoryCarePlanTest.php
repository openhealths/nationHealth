<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\CarePlanRepository;
use App\Repositories\Repository;
use Tests\TestCase;

class RepositoryCarePlanTest extends TestCase
{
    public function test_care_plan_facade_resolves_care_plan_repository(): void
    {
        $this->assertInstanceOf(CarePlanRepository::class, Repository::carePlan());
    }
}
