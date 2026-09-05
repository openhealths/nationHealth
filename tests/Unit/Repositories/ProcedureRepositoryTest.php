<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\ProcedureRepository;
use Tests\TestCase;

class ProcedureRepositoryTest extends TestCase
{
    public function test_constructor_uses_auth_facade_not_repository_namespace(): void
    {
        $repository = new ProcedureRepository(new Procedure());

        $this->assertInstanceOf(ProcedureRepository::class, $repository);
    }
}
