<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\GrantsMedicalEventAbilities;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use GrantsMedicalEventAbilities;
}
