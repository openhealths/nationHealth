<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use Exception;
use App\Core\Arr;
use Carbon\Carbon;
use App\Enums\Status;
use App\Models\Approval;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Enums\ResponseStatus;
use App\Models\Person\Person;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\DB;
use App\Models\DeclarationRequest;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthConnectionException;

class DeclarationEdit extends DeclarationComponent
{
    public function mount(LegalEntity $legalEntity, int $personId, DeclarationRequest $declarationRequest): void
    {
        $this->baseMount($personId);
        $this->declarationRequestId = $declarationRequest->id;

        if (session('showSignModal')) {
            $this->showSignModal = true;
        }

        if ($declarationRequest->dataToBeSigned) {
            $this->printableContent = $declarationRequest->dataToBeSigned['content'];
            $this->dataToBeSigned = $declarationRequest->dataToBeSigned;
        }

        // Set form data
        $this->form->employeeId = $declarationRequest->load('employee:id,uuid')->employee->uuid;
        $this->form->authorizeWith = $declarationRequest->authorizeWith;

        $this->declarationRequestUuid = $declarationRequest->uuid ?? '';

        $this->status = $declarationRequest->status;
    }

    public function syncPersonDataFromEHealth(): void
    {
        $personUuid = $this->form->personId;

        // try {
        //     $response = EHealth::person()->getAuthMethods($personUuid);
            
        //     $responseData = $response->getData();

        //     dd($responseData);
        // } catch (EHealthException|EHealthConnectionException $exception) {
        //     $exception->handle('Error throughout creating approval for getting a person data');

        //     return;
        // }

        $payloadData = [
            'employee_id' => $this->form->employeeId,
            'person_id' => $personUuid,
            'authorize_with' => $this->form->authorizeWith
        ];

        $requestData = EHealth::approval()->getPayloadForPersonDataApproval($payloadData);

        try {
            $response = EHealth::approval()->createApproval($personUuid, $requestData);
            
            $responseData = $response->getData();
            $responseCode = $response->getStatusCode();

            $responseStatus = match($responseCode) {
                ResponseStatus::SYNC->code() => ResponseStatus::SYNC,
                ResponseStatus::ASYNC->code() => ResponseStatus::ASYNC,
                ResponseStatus::SUCCESS->code() => ResponseStatus::SUCCESS,
                ResponseStatus::NOT_FOUND->code() => ResponseStatus::NOT_FOUND,
                default => null
            };
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error throughout creating approval for getting a person data');

            return;
        }
        $jobData= [];
  
        $jobData = [
            'status' => \strtoupper($responseData['status']),
            'processing_method' => $responseStatus?->name,
            'request_data' => null,
            'response_data' => $responseData,
            'eta' => Carbon::parse($responseData['eta'])->setTimezone(config('app.timezone', 'Europe/Kyiv'))
        ];

        DB::transaction(function () use ($jobData, $responseData) {
            $approval = Repository::approval()->create([
                'uuid' => null,
                'approvable_id' => $this->personId,
                'approvable_type' => Person::class,
                'granted_to_id' => null,
                'granted_by_id' => null,
                'status' => Status::NEW->value,
                'reason_id' => null,
            ]);

            // dd($jobData);
            try {
                $job = EhealthJob::create($jobData);
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Error creating eHealth job request after response');

                return;
            }

            $linksData = [];

            foreach (Arr::get($responseData, 'links', []) as $link) {
                $linksData[] = [
                    'linkable_type' => Approval::class,
                    'linkable_id' => $approval->id,
                    'ehealth_job_id' => $job->id,
                    'entity' => $link['entity'],
                    'href' => $link['href']
                ];
            }

            EhealthLink::upsert($linksData, ['linkable_type', 'linkable_id', 'ehealth_job_id']);
        });

        $this->isSyncing = true;

        Repository::person()->updateSynchronizationStatusById($this->personId, $this->isSyncing );
    }
}
