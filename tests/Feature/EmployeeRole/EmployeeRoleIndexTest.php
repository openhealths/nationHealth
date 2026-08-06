<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeRole;

use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\EmployeeRole\EmployeeRoleIndex;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRoleIndexTest extends TestCase
{
    #[Test]
    public function it_translates_already_inactive_conflict_for_deactivate(): void
    {
        $component = new EmployeeRoleIndex();
        $method = new \ReflectionMethod(EmployeeRoleIndex::class, 'translateDeactivateError');
        $method->setAccessible(true);

        $translated = $method->invoke($component, $this->makeConflictException(
            'INACTIVE employee role cannot be DEACTIVATED'
        ));

        $this->assertSame(__('employee-roles.errors.already_inactive'), $translated);
    }

    #[Test]
    public function it_keeps_default_error_for_other_deactivate_failures(): void
    {
        $component = new EmployeeRoleIndex();
        $method = new \ReflectionMethod(EmployeeRoleIndex::class, 'translateDeactivateError');
        $method->setAccessible(true);

        $translated = $method->invoke($component, $this->makeConflictException(
            'Some other conflict'
        ));

        $this->assertNull($translated);
    }

    private function makeConflictException(string $message): EHealthResponseException
    {
        $guzzle = new GuzzleResponse(
            409,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR)
        );

        return new EHealthResponseException(new Response($guzzle));
    }
}
