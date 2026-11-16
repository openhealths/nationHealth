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
     * Show the frontend's current user, their sign-in method, and, after eHealth sign-in, the associated legal entity.
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
     * Show the frontend's current user in the given legal entity and the role chosen at sign-in.
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
