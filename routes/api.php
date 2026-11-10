<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CurrentUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*
 * Routes for the application's own frontend. Passport's token cookie identifies the user, while the session it was
 * issued with supplies the legal entity, the role chosen at login and the eHealth token, so every policy applies
 * exactly as on the web pages.
 */

/*
 * Routes outside a legal entity, open after both local and eHealth sign-in.
 * The eHealth guard goes first so an eHealth session keeps its own roles and permissions.
 */
Route::middleware(['web', 'auth:api', 'auth:ehealth,web', 'verified'])
    ->prefix('v1')
    ->name('api.')
    ->group(static function (): void {
        Route::get('/me', [CurrentUserController::class, 'show'])->name('current-user');
    });

/*
 * Routes inside a legal entity, open only after eHealth sign-in, as on the web pages.
 */
Route::middleware(['web', 'auth:api', 'auth:ehealth', 'verified', 'can:access,legalEntity'])
    ->prefix('v1/legal-entities/{legalEntity}')
    ->whereNumber('legalEntity')
    ->name('api.legal-entity.')
    ->group(static function (): void {
        Route::get('/me', [CurrentUserController::class, 'showInLegalEntity'])->name('current-user');
    });
