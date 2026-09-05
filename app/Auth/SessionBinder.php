<?php

declare(strict_types=1);

namespace App\Auth;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Keeps a single active session per user and ties that session to the client it was issued to.
 *
 * The session cookie alone is not enough to reach the application: the request must also carry the companion cookie
 * issued at login, otherwise the session is treated as replayed and gets terminated.
 *
 * That cookie is rotated as the session is used: nothing in a request tells two clients apart, but only one of them
 * receives the replacement, so a copied set of cookies gives itself away as soon as it presents the value it kept.
 */
class SessionBinder
{
    /**
     * Session key holding the value of the companion cookie issued at login.
     */
    private const string TOKEN_KEY = 'session_binding.token';

    /**
     * Session key holding the token the companion cookie carried before the last rotation.
     */
    private const string PREVIOUS_TOKEN_KEY = 'session_binding.previous_token';

    /**
     * Session key holding the moment the companion token was last rotated.
     */
    private const string ROTATED_AT_KEY = 'session_binding.rotated_at';

    /**
     * Number of seconds a companion token is served before it is replaced.
     */
    private const int ROTATION_INTERVAL = 60;

    /**
     * Number of seconds the replaced token is still accepted after a rotation.
     */
    private const int ROTATION_GRACE = 10;

    /**
     * Number of seconds to wait for eHealth to acknowledge a token revocation.
     */
    private const int REVOCATION_TIMEOUT = 5;

    /**
     * Bind the current session to the user and terminate every session opened earlier.
     *
     * @param  User  $user
     * @param  string|null  $previousBearerToken  eHealth token of the session this browser carried into the login
     * @return void
     */
    public function bind(User $user, ?string $previousBearerToken = null): void
    {
        // The guard regenerates the session while authenticating, which wipes the row this browser came in with,
        // so its token can only be revoked from the value captured before that happened
        $this->revokeEHealthToken($previousBearerToken);

        $this->revokePreviousSession($user);

        $this->issueToken();

        $user->forceFill(['session_id' => Session::getId()])->save();
    }

    /**
     * Determine whether the current request may keep using the session it presented.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return bool
     */
    public function verify(Request $request, User $user): bool
    {
        if ($user->sessionId === null || !hash_equals($user->sessionId, Session::getId())) {
            return false;
        }

        $token = Session::get(self::TOKEN_KEY);

        if (!is_string($token)) {
            return false;
        }

        $presentedToken = (string)$request->cookie($this->cookieName());

        if (hash_equals($token, $presentedToken)) {
            $this->rotateWhenDue($token);

            return true;
        }

        $previousToken = Session::get(self::PREVIOUS_TOKEN_KEY);

        if (!is_string($previousToken) || !hash_equals($previousToken, $presentedToken)) {
            return false;
        }

        // Requests sent before the replacement reached the client still carry the token it knew of,
        // anything older than that is a second client working from a copy of the cookies
        return now()->timestamp - (int)Session::get(self::ROTATED_AT_KEY) <= self::ROTATION_GRACE;
    }

    /**
     * Drop the binding on logout so the cookies left in the browser cannot be replayed.
     *
     * @param  User|null  $user
     * @return void
     */
    public function release(?User $user): void
    {
        // A stale session must not be able to detach the session the user is actually working in
        if ($user?->sessionId !== null && hash_equals($user->sessionId, Session::getId())) {
            $user->forceFill(['session_id' => null])->save();
        }

        Cookie::expire($this->cookieName(), config('session.path'), config('session.domain'));
    }

    /**
     * Hand the client a freshly generated companion token, keeping the one it replaces for a moment.
     *
     * @param  string|null  $replacedToken
     * @return void
     */
    protected function issueToken(?string $replacedToken = null): void
    {
        $token = Str::random(64);

        Session::put(self::TOKEN_KEY, $token);
        Session::put(self::PREVIOUS_TOKEN_KEY, $replacedToken);
        Session::put(self::ROTATED_AT_KEY, now()->timestamp);

        $this->queueCookie($token);
    }

    /**
     * Replace the companion token once it has served its interval, refreshing the cookie meanwhile
     * so it stays alive for as long as the session cookie itself.
     *
     * @param  string  $token
     * @return void
     */
    protected function rotateWhenDue(string $token): void
    {
        if (now()->timestamp - (int)Session::get(self::ROTATED_AT_KEY) < self::ROTATION_INTERVAL) {
            $this->queueCookie($token);

            return;
        }

        $this->issueToken($token);
    }

    /**
     * Cut off the eHealth session behind the session the user was last seen in.
     *
     * The local session is left in place: EnsureSingleSession evicts it on the first request carrying it.
     *
     * @param  User  $user
     * @return void
     */
    protected function revokePreviousSession(User $user): void
    {
        $previousSessionId = $user->sessionId;

        if ($previousSessionId === null || $previousSessionId === Session::getId()) {
            return;
        }

        $this->revokeEHealthToken($this->readBearerToken($previousSessionId));

        // Reading another session marks the shared handler as "already stored", which would turn to write of the
        // freshly regenerated session into an update of a row that does not exist yet
        Session::setExists(false);
    }

    /**
     * Terminate the eHealth session behind the given token, so its access and refresh tokens stop working on the eHealth side as well.
     *
     * @param  string|null  $token
     * @return void
     */
    protected function revokeEHealthToken(?string $token): void
    {
        if ($token === null) {
            return;
        }

        try {
            EHealth::auth()->timeout(self::REVOCATION_TIMEOUT)->logout($token);
        } catch (EHealthException|EHealthConnectionException $exception) {
            // The local session is dropped either way, so a failed revocation must not block the login
            Log::channel('e_health_errors')->error(
                "Error while terminating the previous eHealth session: {$exception->getMessage()}",
                ['exception' => $exception]
            );
        }
    }

    /**
     * Read the eHealth access token out of a stored session other than the current one.
     *
     * @param  string  $sessionId
     * @return string|null
     */
    protected function readBearerToken(string $sessionId): ?string
    {
        $payload = Session::getHandler()->read($sessionId);

        if (empty($payload)) {
            return null;
        }

        $attributes = @unserialize($payload, ['allowed_classes' => false]);

        if (!is_array($attributes)) {
            // Silence here would mean eHealth tokens quietly stop being revoked, for example
            // after session encryption gets enabled or the session store is swapped
            Log::channel('e_health_errors')->warning(
                'Could not read the payload of the previous session, its eHealth token is left untouched'
            );

            return null;
        }

        $token = $attributes[config('ehealth.api.oauth.bearer_token')] ?? null;

        return is_string($token) ? $token : null;
    }

    /**
     * Queue the companion cookie with the same attributes as the session cookie.
     *
     * @param  string  $token
     * @return void
     */
    protected function queueCookie(string $token): void
    {
        Cookie::queue(
            $this->cookieName(),
            $token,
            (int)config('session.lifetime'),
            config('session.path'),
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site')
        );
    }

    /**
     * Get the name of the companion cookie.
     *
     * @return string
     */
    protected function cookieName(): string
    {
        return config('session.cookie') . '_binding';
    }
}
