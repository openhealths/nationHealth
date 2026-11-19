<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Livewire\Referral\ReferralIndex;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReferralRoutesTest extends TestCase
{
    public function test_registry_uses_livewire_and_requires_access_to_the_facility_and_referrals(): void
    {
        $registry = Route::getRoutes()->getByName('referrals.index');

        $this->assertNotNull($registry);
        $this->assertSame(ReferralIndex::class, $registry->getControllerClass());
        $this->assertContains('GET', $registry->methods());
        $this->assertContains('auth:ehealth', $registry->gatherMiddleware());
        $this->assertContains('can:access,legalEntity', $registry->gatherMiddleware());
        $this->assertContains('permission:service_request:read', $registry->gatherMiddleware());
    }

    public function test_referral_actions_are_not_exposed_as_separate_http_endpoints(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('referrals/api/', $route->uri());
            $this->assertStringNotContainsString('api/referrals', $route->uri());
        }

        foreach (['search', 'process', 'complete', 'cancel-usage'] as $action) {
            $this->assertFalse(Route::has('referrals.api.'.$action));
        }
    }
}
