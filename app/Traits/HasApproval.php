<?php

declare(strict_types=1);

namespace App\Traits;

use DB;
use Exception;
use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Enums\Status;
use App\Enums\JobStatus;
use Illuminate\Bus\Batch;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Enums\ResponseStatus;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Session;
use App\Models\MedicalEvents\Sql\Approval;
use App\Exceptions\EHealth\EHealthException;
use App\Repositories\MedicalEvents\Repository;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Notifications\RemoteEHealthLinksNotification;

trait HasApproval
{
    protected const string BATCH_NAME = 'RemoteEHealthLinksProcessing';

    /**
     * Create an eHealth approval for the given approvable model.
     *
     * Validates authorization and the `authorize_with` entry in `$payloadData`
     * ('authorize_with' should contain array with 'type' and 'uuid' keys)
     *
     * The response is handled in one of three ways:
     * - **Synchronous** (`is_verified` present, no links): granted resources are synced
     *   immediately via `Repository::approval()->sync()`.
     * - **Asynchronous** ({@see ResponseStatus::ASYNC}): an {@see EhealthJob} and
     *   associated {@see EhealthLink} records are persisted, a {@see Bus::batch()} is
     *   dispatched on the `sync` queue, and the authenticated user is notified on
     *   completion or failure via {@see RemoteEHealthLinksNotification}.
     * - **Any other outcome**: flashes an error and throws an {@see Exception}.
     *
     * @param  Model  $model  The approvable model (e.g. {@see \App\Models\Person\Person}).
     * @param  array  $payloadData  Approval payload. Must include an `authorize_with` key
     *                              containing the authentication method with `type` and `uuid`
     *                              sub-keys. The `OFFLINE` type is not allowed.
     * @return void
     * @throws Exception If the user lacks permission, `authorize_with` is invalid or missing,
     *                   the eHealth request fails, or the response cannot be processed.
     */
    public function createApproval(Model $model, array $payloadData): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            Session::flash('error', __('patients.policy.approval'));

            throw new Exception('Approval creation failed');
        }

        $authorizeWith = $payloadData['authorize_with'] ?? null;

        if (!$authorizeWith) {
            Session::flash('error', __('patients.errors.authMethod.not_found'));

            throw new Exception('Approval creation failed');
        } elseif ($authorizeWith['type'] === 'OFFLINE') {
            Session::flash('error', __('patients.errors.authMethod.wrong_type'));

            throw new Exception('Approval creation failed');
        }

        $payloadData['authorize_with'] = $authorizeWith['uuid'];

        $requestData = Repository::approval()->formatEHealthRequest($payloadData);

        $approval = Approval::getByModel($model->id, get_class($model))
            ->whereStatus(Status::NEW->value)
            ->first() ?? Repository::approval()->create($requestData, $model);

        $links = [];

        try {
            $response = EHealth::approval()->createApproval($model->uuid, $requestData);

            $responseData = $response->getData();
            $responseCode = $response->getStatusCode();
            $links = Arr::get($responseData, 'links', []);

            $responseStatus = match($responseCode) {
                ResponseStatus::SYNC->code() => ResponseStatus::SYNC,
                ResponseStatus::ASYNC->code() => ResponseStatus::ASYNC,
                ResponseStatus::SUCCESS->code() => ResponseStatus::SUCCESS,
                ResponseStatus::NOT_FOUND->code() => ResponseStatus::NOT_FOUND,
                default => null
            };
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(__('Error throughout creating approval for getting a data for: ' . $model->uuid));

            throw new Exception('Approval creation failed');
        }

        // If $responseData['is_verified'] is not empty, it means that response contains all approval's data so we don't need to create eHealth job in the database.
        // In this case, we just starting job to create approval and update person data in the database.
        // TODO: need to somehow checking the SYNC response. Up to this $responseStatus is always ASYNC, so we can't check it. Need to check it with eHealth team.
        if (empty($links) && isset($responseData['is_verified'])) {
            foreach ($responseData['granted_resources'] ?? [] as $grantedResource) {
                $value = Arr::get($grantedResource, 'identifier.value', null);

                $resourceModel = $model->where('uuid', $value)->first();

                Repository::approval()->sync(modelData: $responseData, approvableModel: $resourceModel, approvalModel: $approval);
            }
        } elseif ($responseStatus === ResponseStatus::ASYNC) {
            $jobData = [];

            $jobData = [
                'status' => \strtoupper($responseData['status']),
                'processing_method' => $responseStatus?->name,
                'request_data' => null,
                'response_data' => $responseData,
                'eta' => Carbon::parse($responseData['eta'])->setTimezone(config('app.timezone', 'Europe/Kyiv'))
            ];

            try {
                $job = EhealthJob::create($jobData);
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, __('Error creating eHealth job request after response'));

                throw new Exception('Approval creation failed');
            }

            DB::transaction(function () use ($approval, $links, $job) {
                $linksData = [];

                foreach ($links as $link) {
                    $linksData[] = [
                        'linkable_type' => get_class($approval),
                        'linkable_id' => $approval->id,
                        'ehealth_job_id' => $job->id,
                        'entity' => $link['entity'],
                        'href' => $link['href'],
                        'status' => JobStatus::PENDING->value,
                    ];
                }

                EhealthLink::upsert($linksData, ['linkable_type', 'linkable_id', 'ehealth_job_id']);
            });

            $user = Auth::user();
            $token = session()->get(config('ehealth.api.oauth.bearer_token'));

            Bus::batch($this->getEHealthRemoteJobsData(legalEntity(), null, $job))
                ->withOption('legal_entity_id', legalEntity()->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(fn () => $user->notify(new RemoteEHealthLinksNotification(__('Approval created successfully'), 'success')))
                ->catch(callback: function (Batch $batch, Throwable $e) use ($user) {
                    $message = __('Approval job failed');
                    Log::error('Approval job batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);

                    $user->notify(new RemoteEHealthLinksNotification($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();
        } else {
            Session::flash('error', __('patients.errors.error_creating_approval'));

            throw new Exception('Approval creation failed');
        }
    }

    /**
     * Resend the SMS verification code for a data update approval.
     *
     * The OTP is re-sent via eHealth and the approval's `updated_at` timestamp is refreshed to restart the SMS validity window.
     *
     * @param  Model  $model  An {@see Approval} to resend the code for, or the approvable
     *                        model (e.g. {@see \App\Models\Person\Person}) whose latest
     *                        approval should be looked up.
     * @return void
     */
    public function resendApprovalSms(Model $model): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            Session::flash('error', __('patients.policy.approval_sms_code'));

            return;
        }

        $approval = is_a($model, Approval::class)
            ? $model
            : Approval::getByModel($model->id, get_class($model))
                ->whereStatus(Status::APPROVED->value)
                ->whereNotNull('uuid')
                ->first();

        if (!$approval) {
            Session::flash('error', __('patients.errors.approval_sms_error'));

            return;
        }

        try {
            EHealth::approval()->resendSms($approval->approvable?->uuid, $approval->uuid);

            $approval->updated_at = now();
            $approval->save();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(__('Error occurred while resending approval SMS code for the person data update'));

            return;
        }
    }

    /**
     * Verify the OTP code against the pending approval and sync the result locally.
     *
     * Looks up the most recent {@see Status::APPROVED} approval for `$approvable`, then
     * submits `$code` to eHealth via `EHealth::approval()->verify()`.
     * On a `200` response the approval details are fetched and persisted locally through
     * `Repository::approval()->sync()`.
     * Any other status code or caught exception causes an error flash, error logging, and returns `false`.
     *
     * @param  Model  $approvable  The model (e.g. {@see Person}) the approval belongs to.
     * @param  int|string  $code  The OTP verification code submitted by the user.
     * @return bool `true` when the approval was successfully verified and synced, `false` otherwise.
     */
    public function verifyApproval(Model $approvable, int|string $code): bool
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            Session::flash('error', __('patients.policy.approval_sms_code'));

            return false;
        }

        $approval = Approval::getByModel($approvable?->id, get_class($approvable))
            ->whereStatus(Status::APPROVED->value)
            ->first();

        if (!$approval) {
            Session::flash('error', __('patients.errors.approval_not_found'));

            return false;
        }

        try {
            $verifyResponse = EHealth::approval()->verify($approvable?->uuid, $approval->uuid, ['code' => (int)$code]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(__('Error occurred while trying to verify current Approval: ' . $approval->uuid));
            \Log::error('Error occurred while trying to verify current Approval: ' . $approval->uuid, ['exception' => $exception, 'verify_response' => $verifyResponse ?? null]);

            return false;
        }

        if ($verifyResponse->getStatusCode() === 200) {
            try {
                $response = EHealth::approval()->getApprovalDetails($approvable?->uuid, $approval->uuid);
                $responseData = $response->getData();

                Repository::approval()->sync(modelData: $responseData, approvableModel: $approvable, approvalModel: $approval);
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle(__('Error occurred while trying to get Approval details: ' . $approval->uuid));

                return false;
            }
        } else {
            Session::flash('error', __('patients.errors.approval_verification_failed'));

            Log::error('Approval verification failed', [
                'approval_uuid' => $approval->uuid,
                'response_status' => $verifyResponse->getStatusCode(),
                'response_data' => $verifyResponse->getData()
            ]);

            return false;
        }

        return true;
    }
}
