<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\ResponseStatus;
use App\Enums\Person\AuthStep;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Models\Approval;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Livewire\Person\Traits\InteractsWithAuthenticationMethods;
use App\Livewire\Person\Traits\ManagesConfidantPersonRelationships;
use App\Models\Person\Person;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;
use App\Repositories\Repository;
use App\Repositories\MedicalEvents\Repository as MERepository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\FormTrait;
use Auth;
use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;
use Livewire\WithFileUploads;
use Throwable;

class PatientData extends BasePatientComponent
{
    use FormTrait;
    use InteractsWithAuthenticationMethods;
    use ManagesConfidantPersonRelationships;
    use WithFileUploads;
    use BatchLegalEntityQueries;

    public Form $form;

    public string $firstName;

    public string $lastName;

    public array $phones = [];

    public array $confidantPersonRelationships = [];

    public bool $canManageConfidantRelationships = true;

    public bool $isIncapacitated = false;

    public array $confidantPerson = [];

    public ?string $selectedConfidantPersonId = null;

    public ?string $invalidPersonId = null;

    public array $newConfidantPerson = ['documentsRelationship' => []];

    /**
     * List of patient authentication methods.
     *
     * @var array
     */
    public array $authenticationMethods = [];

    public bool $showAuthMethodModal = false;

    public AuthStep $authStep = AuthStep::INITIAL;

    public ?string $phoneNumber = null;

    public int $code;

    public string $newPhoneNumber;

    public int $verificationCode;

    public string $requestId;

    public string $selectedAuthMethodUuid;

    public string $selectedAuthMethodType = '';

    public string $alias;

    public string $confidantPersonId;

    public string $confidantPersonRelationshipRequestId;

    public array $documentsRelationship = [];

    public bool $showSignatureDrawer = false;

    public bool $showAuthDrawer = false;

    public bool $showConfidantPersonDrawer = false;

    public bool $showDeactivateConfidantPersonDrawer = false;

    public array $confidantPersonRelationshipRequests = [];

    public array $approvedData;

    public bool $showTerminateModal = false;

    public ?string $authDrawerMode = null;

    public bool $showSignatureModal = false;

    public bool $showAdditionalParams = false;

    public array $uploadedDocuments = [];

    public array $uploadedFiles = [];

    public array $dictionaryNames = [
        'DOCUMENT_TYPE',
        'DOCUMENT_RELATIONSHIP_TYPE',
        'GENDER',
        'PHONE_TYPE'
    ];

    public bool $showConfirmationUpdateModal = false;

    protected const string BATCH_NAME = 'RemoteEHealthLinksProcessing';

    /**
     * Status of sync person data with eHealth.
     * {true}: when we get person data from eHealth and update local person data,
     * {false}: when we didn't do it yet or sync isn't needed.
     *
     * @var bool
     */
    public bool $isSyncing = false;

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $patient = Person::with([
            'phones',
            'authenticationMethods',
            'confidantPersons.person.documents',
            'confidantPersons.person.phones',
            'confidantPersons.documentsRelationship'
        ])->whereId($this->personId)->firstOrFail();

        $this->firstName = $patient->firstName;
        $this->lastName = $patient->lastName;
        $this->phones = $patient->phones->toArray();
        $this->form->person = Arr::toCamelCase($patient->toArray());
        $this->confidantPersonRelationshipRequests = $this->loadConfidantPersonRelationshipRequests($patient);
        $this->form->uploadedDocuments = [];
        $this->refreshAuthenticationMethods($patient);
        $this->isSyncing = $patient->isSyncing;
    }

    /**
     * Get patient verification status.
     *
     * @return void
     */
    public function getVerificationStatus(): void
    {
        try {
            $response = EHealth::person()->getPersonVerificationDetails($this->uuid);
            $validated = $response->validate();

            try {
                Repository::person()->updateVerificationStatusById($this->uuid, $validated['verification_status']);

                $this->verificationStatus = $validated['verification_status'];
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Error when updating person verification status');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting person verification details');

            return;
        }
    }

    /**
     * Get patient confidant persons.
     *
     * @return void
     */
    public function getConfidantPersons(): void
    {
        try {
            $response = EHealth::person()->getConfidantPersonRelationships($this->uuid);

            $this->confidantPersonRelationships = Arr::toCamelCase($response->validate());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting confidant person relationships');

            return;
        }
    }

    protected function uploadDocuments(): void
    {
        if (count($this->form->uploadedDocuments) !== count($this->uploadedDocuments)) {
            Session::flash('error', __('patients.messages.upload_all_files'));

            return;
        }

        foreach ($this->form->uploadedDocuments as $key => $document) {
            try {
                $mimeType = $document->getMimeType();
                $response = Http::withHeaders(['Content-Type' => $mimeType])
                    ->withBody(file_get_contents($document->getRealPath()), $mimeType)
                    ->put(trim($this->uploadedDocuments[$key]['url']));

                $this->uploadedFiles[$key] = $response->successful();
            } catch (Throwable) {
                $this->uploadedFiles[$key] = false;
            }
        }

        if (in_array(false, $this->uploadedFiles, true)) {
            Session::flash('error', __('messages.database_error'));

            return;
        }

        Session::flash('success', __('patients.messages.files_uploaded_successfully'));
    }

    private function refreshAuthenticationMethods(Person $patient): void
    {
        $confidantPersons = $patient->confidantPersons->keyBy(
            fn ($confidantPerson) => $confidantPerson->person->uuid
        );

        $this->authenticationMethods = $patient->authenticationMethods
            ->map(function (AuthenticationMethodModel $authenticationMethod) use ($confidantPersons): array {
                $method = Arr::toCamelCase($authenticationMethod->toArray());

                if ($method['type'] !== AuthenticationMethod::THIRD_PERSON->value) {
                    return $method;
                }

                $relationship = $confidantPersons->get($method['value']);

                if ($relationship === null) {
                    return $method;
                }

                $person = $relationship->person;
                $method['confidantPerson'] = [
                    'name' => $person->fullName,
                    'taxId' => $person->taxId,
                    'unzr' => $person->unzr,
                    'documentsPerson' => $person->documents->toArray(),
                    'phones' => $person->phones->first() === null
                        ? null
                        : ['number' => $person->phones->first()->number]
                ];

                return $method;
            })
            ->values()
            ->toArray();

        $this->phoneNumber = collect($this->authenticationMethods)
            ->firstWhere('type', AuthenticationMethod::OTP->value)['phoneNumber'] ?? null;
    }

    public function syncPersonDataFromEHealth(): void
    {
        if (Auth::user()->cannot('syncPersonData', Person::class)) {
            Session::flash('error', __('patients.policy.personal_data_update'));

            return;
        }

        if ($this->isSyncing && Approval::where('approvable_type', Person::class)->where('approvable_id', $this->personId)->whereNotNull('uuid')->exists()) {
            $this->showConfirmationUpdateModal = true;

            return;
        }

        // $this->getAuthenticationMethods();

        $authorizeWith = collect($this->authenticationMethods)->firstWhere('type', AuthenticationMethod::OTP->value) ??
            collect($this->authenticationMethods)->firstWhere('type', AuthenticationMethod::THIRD_PERSON->value) ??
            collect($this->authenticationMethods)->firstWhere('type', AuthenticationMethod::OFFLINE->value) ?? null;

        if (!$authorizeWith) {
            Session::flash('error', __('patients.errors.authMethod.not_found'));

            return;
        } else if ($authorizeWith['type'] === 'OFFLINE') {
            Session::flash('error', __('patients.errors.authMethod.wrong_type'));

            return;
        }

        $employee = Auth::user()->activeDoctorEmployee();

        $payloadData = [
            'granted_to' => ['value' => $employee->uuid, 'type' => ['coding' => [['code' => 'employee']]]],
            'created_by' => ['value' => $employee->uuid, 'type' => ['coding' => [['code' => 'employee']]]],
            'person' => ['value' => $this->uuid, 'type' => ['coding' => [['code' => 'person']]]],
            'authorize_with' => $authorizeWith['uuid']
        ];

        $requestData = MERepository::approval()->formatApprovalEHealthRequest($payloadData);
        dd($requestData);
        $links = [];

        try {
            $response = EHealth::approval()->createApproval($this->uuid, $requestData);

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
            $exception->handle('Error throughout creating approval for getting a person data');

            return;
        }

        // If $responseData['is_verified'] is not empty, it means that response contains all approval's data so we don't need to create eHealth job in the database.
        // In this case, we just starting job to create approval and update person data in the database.
        if (!$responseCode && empty($links) && !empty($responseData['is_verified'])) {
            // TODO: Start job to create approval and update person data in the database.
        } else {
            $jobData= [];

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
                $this->handleDatabaseErrors($exception, 'Error creating eHealth job request after response');

                return;
            }

            DB::transaction(function () use ($authorizeWith, $links, $job) {
                $linksData = [];

                foreach ($links as $link) {
                    $approval = MERepository::approval()->create(personId: $this->personId);

                    // $approval->authenticationMethod()->associate($authorizeWith['id'] ?? null);
                    // $approval->save();

                    $linksData[] = [
                        'linkable_type' => Approval::class,
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
                ->then(fn() => $user->notify(new RemoteEHealthLinksNotification(__('Approval created successfully'), 'success')))
                ->catch(callback: function (Batch $batch, Throwable $e) use ($user) {
                    $message = __('Approval job failed');
                    Log::error('Approval job batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);

                    $user->notify(new RemoteEHealthLinksNotification($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();
        }

        $this->isSyncing = true;

        Repository::person()->updateSynchronizationStatusById($this->personId, $this->isSyncing);

        $this->showConfirmationUpdateModal = true;
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-data');
    }
}
