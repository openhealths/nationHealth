<?php

declare(strict_types=1);

namespace App\Listeners;

use Throwable;
use App\Events\LegalEntityCreate;
use App\Mail\OwnerCredentialsMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class SendUserCredentialsListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Amount of the trying attempt when fail
     *
     * Important:
     * - The `$tries` property must be declared as `public` to be recognized by Laravel's queue worker.
     * - If these properties are `protected` or `private`, they will be ignored.
     * - Laravel uses reflection or direct property access (e.g., `$this->tries`) and does not
     *   call any getter methods for these values.
     *
     * Example:
     * public int $tries = 3;
     *
     * Related Docs:
     *
     * @see https://laravel.com/docs/queues#retrying-failed-jobs
     *
     * @var int
     */
    public ?int $tries;

    /**
     * How many seconds should be passed before the next try
     *
     * Important:
     * - The `$backoff` property must be declared as `public` to be recognized by Laravel's queue worker.
     * - If these properties are `protected` or `private`, they will be ignored.
     * - Laravel uses reflection or direct property access (e.g., `$this->tries`) and does not
     *   call any getter methods for these values.
     *
     * Example:
     * public int $tries = 3;
     *
     * Related Docs:
     *
     * @see https://laravel.com/docs/queues#retrying-failed-jobs
     *
     * @var int
     */
    public ?int $backoff;

    public function __construct()
    {
        $this->tries = config('ehealth.emailers.failCredentialsTries');
        $this->backoff = config('ehealth.emailers.credentialsQueueTimeout');
    }

    /**
     * Handle the event.
     */
    public function handle(LegalEntityCreate $event): void
    {
        // Check: if user that create the LegalEntity is Owner-user just skip this listener
        if (strtolower($event->authenticatedUser->email) === $event->owner->email) {
            return;
        }

        // Send a link to the owner's email to verify the given address
        if ($event->owner instanceof MustVerifyEmail && !$event->owner->hasVerifiedEmail()) {
            Log::info(static::class . ": send email verification LINK: to email: {$event->owner->email}");

            $event->owner->sendEmailVerificationNotification();
        }

        Log::info(static::class . ' started for: ' . $event->owner->email);

        // Send a credentials for the owner's account (only for local login!)
        Mail::to($event->owner->email)
            ->send(new OwnerCredentialsMail($event->owner->email, $event->password));

        Log::info("LegalEntity: User credentials was sended to the {$event->owner->email} address");
    }

    /**
     * Log when failed
     *
     * @param  LegalEntityCreate  $event
     * @param  \Throwable  $err
     * @return void
     */
    public function failed(LegalEntityCreate $event, Throwable $err): void
    {
        Log::error(static::class . 'failed', [
            'user_email' => $event->owner->email,
            'error' => $err->getMessage()
        ]);
    }
}
