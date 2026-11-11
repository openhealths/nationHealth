<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\Permission;
use DOMDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

class ClinicalSidebarNavigationTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;

    public static function routes(): array
    {
        return [
            'plan registry' => ['care-plans.index', 'care-plans.index', true],
            'plan detail' => ['care-plans.show', 'care-plans.index', true],
            'patient plans' => ['persons.care-plans', 'care-plans.index', true],
            'device registry' => ['device-requests.index', 'device-requests.index', true],
            'patient devices' => ['persons.device-requests', 'device-requests.index', true],
            'patient device detail' => ['persons.device-requests.view', 'device-requests.index', true],
            'plan menu on device page' => ['persons.device-requests.view', 'care-plans.index', false],
            'device menu on plan page' => ['care-plans.show', 'device-requests.index', false],
        ];
    }

    #[DataProvider('routes')]
    public function test_sidebar_highlights_the_current_clinical_document_family(
        string $routeName,
        string $registry,
        bool $isActive,
    ): void {
        Http::preventStrayRequests();
        Queue::fake();
        $scopes = ['person:read', 'care_plan:read', 'device_request:read', 'service_request:read'];
        $context = $this->compositionFixture(LegalEntity::TYPE_OUTPATIENT, Role::SPECIALIST, []);
        $user = $context['user'];
        // Use the clinical guard so the local login's limited-action menu cannot hide the result.
        Auth::guard('web')->logout();
        $this->actingAs($user, 'ehealth');
        foreach ($scopes as $scope) {
            $permission = Permission::findOrCreate($scope, 'ehealth');
            $permission->legalEntityTypes()->syncWithoutDetaching([$context['legalEntity']->legalEntityTypeId]);
            $user->givePermissionToParent($permission);
        }
        $this->grantReferralEmployeeRole($user, $context['employee'], 'ehealth');
        Session::put('ehealth_legal_entity_uuid', $context['legalEntity']->uuid);
        $this->assertTrue($user->can('access', $context['legalEntity']));
        $this->assertTrue($user->cannot('limitedAction', LegalEntity::class));

        $parameters = [
            'legalEntity' => $context['legalEntity']->id,
            'person' => $context['person']->id,
            'carePlan' => 10,
            'deviceRequest' => (string) Str::uuid(),
        ];
        $request = Request::create(route($routeName, $parameters));
        $currentRoute = Route::getRoutes()->getByName($routeName)->bind($request);
        $request->setRouteResolver(static fn () => $currentRoute);
        $this->app->instance('request', $request);

        $document = new DOMDocument();
        @$document->loadHTML(view('livewire.components.sidebar')->render());
        $classes = null;
        foreach ($document->getElementsByTagName('a') as $link) {
            if ($link->getAttribute('href') === route($registry, [$context['legalEntity']])) {
                $classes = preg_split('/\s+/', trim($link->getAttribute('class')));
                break;
            }
        }
        $this->assertNotNull($classes);
        $this->assertSame($isActive, in_array('menu-item-active', $classes, true));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
