<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Dto\CarePlan\Model as CarePlanData;
use App\Dto\CarePlanRequest\Model as CarePlanRequestData;
use App\Enums\JobStatus;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\CarePlanRequest;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CarePlanRequestRepository
{
    public function create(Person $patient, LegalEntity $legalEntity, CarePlanRequestData $data): CarePlanRequest
    {
        $request = new CarePlanRequest();
        $request->person()->associate($patient);
        $request->legalEntityId = $legalEntity->id;
        $request->userId = Auth::id();
        $request->uuid = $data->uuid;

        try {
            return DB::transaction(fn (): CarePlanRequest => $this->persist($request, $data));
        } catch (UniqueConstraintViolationException $exception) {
            // Two requests from the same open form can reach the first insert together.
            if (!CarePlanRequest::forEditor($legalEntity->id, Auth::id())->whereUuid($data->uuid)->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['submission' => __('care-plan.request_changed')]);
        }
    }

    public function updateRequest(CarePlanRequest $draft, CarePlanRequestData $data): CarePlanRequest
    {
        return DB::transaction(function () use ($draft, $data): CarePlanRequest {
            $request = $this->editableRevision($draft, $data->version);

            return $this->persist($request, $data);
        });
    }

    public function delete(CarePlanRequest $draft, int $version): void
    {
        DB::transaction(fn () => $this->editableRevision($draft, $version)->delete());
    }

    protected function persist(CarePlanRequest $request, CarePlanRequestData $data): CarePlanRequest
    {
        objectMapper()->map($data, $request);
        $request->conditionUuid = $data->conditionUuid;
        $request->authorUuid = $data->authorUuid;
        $request->addresses = $data->addresses;
        $request->version = ($data->version ?? 0) + 1;
        $request->save();

        return $request->refresh();
    }

    protected function editableRevision(CarePlanRequest $draft, ?int $version): CarePlanRequest
    {
        $request = CarePlanRequest::forEditor(legalEntity()->id, Auth::id())
            ->whereUuid($draft->uuid)->wherePersonId($draft->personId)->lockForUpdate()->findOrFail($draft->id);
        if ($request->carePlanId !== null || $request->ehealthLinks()->whereStatus(JobStatus::PENDING->value)->exists()) {
            throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
        }
        if ($request->version !== $version) {
            throw ValidationException::withMessages(['submission' => __('care-plan.request_changed')]);
        }

        return $request;
    }

    public function beginSubmission(CarePlanRequest $draft, array $changes): EhealthLink
    {
        return DB::transaction(function () use ($draft, $changes): EhealthLink {
            $revision = $this->editableRevision($draft, $draft->version);

            return RemoteEHealthLinksProcessing::startSubmission($revision, ['version' => $revision->version, 'changes' => $changes]);
        });
    }

    public function finishSubmission(EhealthLink $link, array $outcome, ?CarePlanData $details): bool
    {
        return DB::transaction(function () use ($link, $outcome, $details): bool {
            $link = EhealthLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->status !== JobStatus::PENDING->value) {
                return false;
            }

            $request = $link->linkable()->lockForUpdate()->firstOrFail();
            if ($details !== null) {
                $plan = new CarePlan();
                $plan->personId = $request->personId;
                $plan->legalEntityId = $request->legalEntityId;
                $plan = Repository::carePlan()->save($plan, $details);
                $request->carePlan()->associate($plan)->save();
                // Existing plan details and the inpatient approval job follow the confirmed plan's submission.
                $link->linkable()->associate($plan);
            }

            $status = $details !== null ? JobStatus::PROCESSED->value : JobStatus::FAILED->value;
            $error = $outcome['error'] ?? __('errors.ehealth.messages.request_error');
            $link->job()->update(['status' => $status, 'response_data' => $outcome]);
            $link->update([
                'status' => $status,
                'error' => $details === null ? (is_array($error) ? $error : ['message' => $error]) : null,
                'error_code' => $details === null ? ($outcome['status_code'] ?? null) : null,
            ]);

            return true;
        });
    }
}
