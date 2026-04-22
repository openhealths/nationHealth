<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Closure;
use Carbon\Carbon;
use App\Models\User;
use App\Models\LegalEntity;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Events\EHealthUserLogin;
use App\Mail\UserCredentialsMail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use App\Classes\eHealth\Api\EmployeeApi;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\Exceptions\ApiException;
use App\Classes\eHealth\Request as EHealthRequest;
use Illuminate\Contracts\Validation\Validator as ResponseValidator;

class EHealthLoginController extends Controller
{
    protected bool $isFirstLogin = false;

    /**
     * This method is called when the user is redirected back from eHealth after it's successful authentication
     *
     * @param  Request  $request
     * @return null|RedirectResponse
     * @throws ApiException
     */
    public function __invoke(Request $request): ?RedirectResponse
    {
        // get the email entered by the user in the login form
        $sessionEmail = Session::pull('selected_email');
        $testUser = $sessionEmail && in_array($sessionEmail, config('ehealth.test.emails'), true);

        // exchange code to token && Pass certain emails anyway for testing purposes
        if (!$testUser && (config('ehealth.api.callback_prod') === false)) {
            $code = $request->input('code');
            $url = 'http://localhost/ehealth/oauth?code=' . $code;

            return redirect($url);
        }

        if (!$request->has('code')) {
            return Redirect::route('login');
        }

        $loginedGuard = Session::get('logined_guard', 'web');

        $selectedLegalEntityUuidFromSession = Session::pull('selected_legal_entity_uuid_for_ehealth');

        if (!$selectedLegalEntityUuidFromSession) {
            Log::warning('Legal Entity is not selected');

            return $this->breakAuth('auth.login.error.legal_entity_identity');
        }

        $eHealthTokenResponseData = $this->sendEHealthTokenRequest($request, $selectedLegalEntityUuidFromSession);

        if (empty($eHealthTokenResponseData)) {
            return Redirect::route('login')->with('error', __('auth.login.error.user_identity'));
        }

        $validator = $this->validateEHealthTokenResponse($eHealthTokenResponseData);

        if ($validator->fails()) {
            Log::error(__('auth.login.error.validation.auth', [], 'en'), ['errors' => $validator->errors()]);

            return Redirect::route('login')->with('error', __('auth.login.error.validation.auth'));
        }

        $validatedEHealthTokenData = $validator->validated();

        app(TokenStorage::class)->store($validatedEHealthTokenData);

        $authUserUUID = $validatedEHealthTokenData['user_id'];
        $authLegalEntityUUID = $validatedEHealthTokenData['details']['client_id'];

        Session::put('ehealth_legal_entity_uuid', $authLegalEntityUUID);

        // This checks if the user chose one LE, but eHealth returned another
        if ($selectedLegalEntityUuidFromSession !== $authLegalEntityUUID) {
            Log::warning('User selected a different Legal Entity in form than eHealth returned.', [
                'Selected in form' => $selectedLegalEntityUuidFromSession,
                'Returned by eHealth' => $authLegalEntityUUID,
                'User UUID' => $authUserUUID,
            ]);

            return $this->breakAuth('auth.login.error.legal_entity_identity');
        }

        $legalEntity = LegalEntity::whereUuid($authLegalEntityUUID)->firstOrFail();

        Auth::shouldUse($loginedGuard);

        $user = $this->findOrCreateUser($legalEntity, $authUserUUID);

        if (!$user) {
            Log::error(__('auth.login.error.user_authentication', [], 'en'));

            return $this->breakAuth('auth.login.error.user_authentication');
        }

        if ($testUser && ($sessionEmail !== $user->email)) {
            Log::error(__('auth.login.error.test_user_email', [], 'en'));

            return $this->breakAuth('auth.login.error.test_user_email');
        }

        Auth::guard($loginedGuard)->login($user);

        $ehealthScopes = explode(
            ' ',
            trim(data_get($validatedEHealthTokenData, 'details.scope'))
        );

        $user->syncPermissions($ehealthScopes);

        EHealthUserLogin::dispatch($user, $legalEntity, $authUserUUID, $this->isFirstLogin, $loginedGuard);

        $user->refresh();

        if (!$user->party) {

            Session::put('selected_legal_entity_uuid', $legalEntity->uuid);
            $user->syncPermissions($ehealthScopes);

            return Redirect::route('party.verify');
        }

        if ($legalEntity) {
            Log::info(__('auth.login.success.user_auth', [], 'en'), ['User ID' => $user->id]);

            // Respect EHealth scopes
            $user->syncPermissions($ehealthScopes);

            return Redirect::route('dashboard', [$legalEntity])->with(
                'success',
                $this->isFirstLogin ? __('auth.login.success.new_user_auth') : null
            );
        }

        Auth::guard($loginedGuard)->logout();

        return Redirect::route('login')->with('error', __('auth.login.error.legal_entity.wrong_request'));
    }

    /**
     * Finds an existing user or prepares a new one for the first login.
     * This method NO LONGER performs data synchronization.
     *
     * @param  LegalEntity  $legalEntity
     * @param  string  $authUserUUID
     * @return User|null
     * @throws ApiException
     */
    protected function findOrCreateUser(LegalEntity $legalEntity, string $authUserUUID): ?User
    {
        $user = User::with('party')->where('uuid', $authUserUUID)->first();

        $syncStatus = $legalEntity->getEntityStatus();

        // If user already logged in before and legal entity sync is completed or processing
        if ($user && $syncStatus) {
            setPermissionsTeamId($legalEntity->id);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user;
        }

        $userDetailsValidator = $this->validateUserDetailsResponse(EmployeeApi::getUserDetails());

        if ($userDetailsValidator->fails()) {
            Log::error(
                __('auth.login.error.validation.user_details', [], 'en'),
                ['errors' => $userDetailsValidator->errors()]
            );

            return null;
        }

        [
            'id' => $ehealthUserId,
            'email' => $ehealthEmail,
            'inserted_at' => $ehealthInsertedAt
        ] = $userDetailsValidator->validated();

        if ($ehealthUserId !== $authUserUUID) {
            Log::error(__('auth.login.error.user_identity', [], 'en'));

            return null;
        }

        // If user exist in DB but not logged in before
        $user = User::where('email', $ehealthEmail)->first();

        if (!$user) {
            $password = Str::random(8);

            // When the user try login to eHealth directly without having a local user account
            $user = User::forceCreate([
                'uuid' => $ehealthUserId,
                'email' => $ehealthEmail,
                'password' => Hash::make($password),
                'inserted_at' => Carbon::parse($ehealthInsertedAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'email_verified_at' => now()
            ]);

            try {
                Mail::to($user->email)->send(new UserCredentialsMail($ehealthEmail, $password));
            } catch (\Exception $e) {
                Log::error('Failed to send credentials email to user.', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->isFirstLogin = true;

        // User can be created before first ehealth login (e.g. OWNER or any local admin)
        if (!$user->uuid) {
            $user->update(['uuid' => $ehealthUserId, 'inserted_at' => Carbon::parse($ehealthInsertedAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')]);
        }

        setPermissionsTeamId($legalEntity->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user;
    }

    /**
     * Send request to EHealth to get the token for an auth code,
     * see: https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/oauth/exchange-oauth-code-grant-to-access-token
     *
     * @param  Request  $request
     * @param  string  $selectedLegalEntityUuidFromSession
     * @return array
     * @throws ApiException
     */
    protected function sendEHealthTokenRequest(Request $request, string $selectedLegalEntityUuidFromSession): array
    {
        return EmployeeApi::authenticate(
            $request->input('code'),
            $selectedLegalEntityUuidFromSession,
        );
    }

    /**
     * If any error occurs...
     *
     * @param  string  $err  Text error message via translation
     * @return RedirectResponse
     * @throws ApiException
     */
    protected function breakAuth(string $err = ''): RedirectResponse
    {
        $authEhealth = config('ehealth.api.auth_ehealth');

        // Logout user from the system
        if (Session::has($authEhealth) || Session::has(config('ehealth.api.oauth.bearer_token'))) {
            new EHealthRequest('POST', config('ehealth.api.oauth.logout'), [])->sendRequest();

            // Forget bearer token and other token's data
            app(TokenStorage::class)->clear();
        }

        // Forget session data
        Session::forget($authEhealth);
        Session::forget('logined_guard');

        // Redirect to login page with error message
        $err = $err ?: 'auth.login.error.common';

        $logMessage = __($err, [], 'en');

        Log::error($logMessage);

        $errorMessage = __($err);

        return Redirect::to('/login')->with('error', $errorMessage);
    }

    /**
     * Validate EHealth token exchange response
     * see response example: https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/oauth/exchange-oauth-code-grant-to-access-token?console=1
     *
     * @param  array  $data
     * @return ResponseValidator Returned only specified fields
     */
    protected function validateEHealthTokenResponse(array $data): ResponseValidator
    {
        return Validator::make($data, [
            'details' => ['required', 'array'],
            'details.client_id' => ['required', 'uuid', Rule::exists('legal_entities', 'uuid')],
            'details.scope' => [
                'required',
                function (string $attribute, string $value, Closure $fail) {
                    if ($attribute !== 'details.scope') {
                        return;
                    }

                    $scopesReceived = explode(' ', $value);
                    $scopesAvailable = collect(config('ehealth.roles'))
                        ->flatten()
                        ->unique()
                        ->toArray();
                    $diff = array_diff($scopesReceived, $scopesAvailable);

                    if (empty($diff)) {
                        return;
                    }

                    $fail('The following scopes are unsupported: ' . implode(', ', $diff));
                }
            ],
            'details.refresh_token' => ['required', 'string'],
            'user_id' => ['required', 'uuid'],
            'value' => ['required', 'string'],
            'expires_at' => ['required', 'numeric'],
        ]);
    }

    /**
     * Check authentication $response schema for errors
     *
     * @param  array  $data
     * @return ResponseValidator Returned only specified fields
     */
    protected function validateUserDetailsResponse(array $data): ResponseValidator
    {
        return Validator::make($data, [
            'id' => 'required|string',
            'email' => 'required|string',
            'is_blocked' => 'required|bool',
            'block_reason' => 'nullable|string',
            'person_id' => 'nullable|string',
            'tax_id' => 'nullable|string',
            'settings' => 'nullable|array',
            'inserted_at' => 'required|string',
            'updated_at' => 'required|string',
        ]);
    }
}
