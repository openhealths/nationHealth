<?php

declare(strict_types=1);

use Illuminate\Http\Request;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

//TODO REMOVE AFTER TESTING
Route::post('/v1/send-request', [App\Classes\eHealth\ApiRequest::class, 'sendRequest']);

Route::prefix('v1/referrals')->group(function () {
    Route::get('/search', [App\Http\Controllers\Api\ReferralController::class, 'search']);
    Route::post('/{uuid}/process', [App\Http\Controllers\Api\ReferralController::class, 'process']);
    Route::post('/{uuid}/complete', [App\Http\Controllers\Api\ReferralController::class, 'complete']);
    Route::post('/{uuid}/cancel-usage', [App\Http\Controllers\Api\ReferralController::class, 'cancelUsage']);
});
