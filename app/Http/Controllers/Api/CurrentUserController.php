<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class CurrentUserController extends Controller
{
    /**
     * Show who the frontend is working as, how the user signed in and, after eHealth sign-in, the legal entity it was for.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $guard = Auth::getDefaultDriver();

        return response()->json([
            'id' => $request->user()->id,
            'email' => $request->user()->email,
            'guard' => $guard,
            'legal_entity_id' => $guard === 'ehealth'
                ? LegalEntity::whereUuid(Session::get('ehealth_legal_entity_uuid'))->value('id')
                : null
        ]);
    }

    /**
     * Show who the frontend is working as in a legal entity: the user, the legal entity and the role chosen at login.
     *
     * @param  Request  $request
     * @param  LegalEntity  $legalEntity
     * @return JsonResponse
     */
    public function showInLegalEntity(Request $request, LegalEntity $legalEntity): JsonResponse
    {
        return response()->json([
            'id' => $request->user()->id,
            'email' => $request->user()->email,
            'legal_entity_id' => $legalEntity->id,
            'role' => Session::get('single_role_login')
        ]);
    }
}
