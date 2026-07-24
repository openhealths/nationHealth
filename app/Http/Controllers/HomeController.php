<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * Map of scopes to routes.
     * The order defines priority: the script checks from top to bottom.
     *
     * Key: The eHealth scope to check.
     * Value: The route name to redirect to.
     */
    protected const array SCOPE_REDIRECT_MAP = [
        // 1. Priority: Doctor / Specialist
        // If user can create person requests (e.g., register patients) -> redirect to patient list.
        'person_request:write' => 'persons.index',

        // 2. Priority: HR
        // If user can create/write employee requests -> redirect to employee list.
        'employee_request:write' => 'employee.index',

        // 3. Priority: Owner / Top Management
        // If user has access to view/read legal entity data -> redirect to details.
        'legal_entity:read' => 'legal-entity.details',
    ];

    /**
     * Show the application landing page.
     *
     * @return View|RedirectResponse
     */
    public function index()
    {
        if (Auth::check()) {
            return $this->dashboard();
        }

        $email = config('app.email');
        $phone = config('app.phone');

        return view('home', compact('email', 'phone'));
    }

    /**
     * Dispatch the user to the appropriate page based on their scopes (permissions).
     *
     * @return RedirectResponse
     */
    public function dashboard(): RedirectResponse
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        // 1. Resolve Legal Entity Context
        $le = legalEntity();

        if (!$le) {
            return redirect()->route('legalEntity.select');
        }

        // 2. Iterate through the Priority Map
        // We strictly check scopes. The first match determines the destination.
        foreach (self::SCOPE_REDIRECT_MAP as $scope => $route) {
            if ($user->can($scope)) {
                return redirect()->route($route, ['legalEntity' => $le->id]);
            }
        }

        // 3. Ultimate Fallback
        // If no scopes matched, redirect to external dashboard.
        return redirect()->away('https://openhealths.com/dashboard');
    }
}
