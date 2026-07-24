<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\MergeRequest\Status as MergeRequestStatus;
use App\Enums\Preperson\Status;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MergeRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Traits\FormTrait;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class PrepersonMerge extends Component
{
    use FormTrait;
    use WithFileUploads;

    private const int OTP_RESEND_LIMIT = 1;

    public Form $form;

    public array $dictionaryNames = ['DOCUMENT_TYPE', 'LANGUAGE'];

    public bool $showAdditionalParams = false;

    /**
     * Local database ID of the preperson whose records will be merged; used to check its episodes.
     *
     * @var int
     */
    public int $prepersonId;

    /**
     * eHealth MPI identifier of the preperson whose records will be merged; shown in the drawer title.
     *
     * @var string
     */
    public string $prepersonUuid;

    /**
     * Identified patients found in eHealth for the merge drawer, shaped for the Alpine results template.
     *
     * @var array
     */
    public array $mergeSearchPatients = [];

    /**
     * eHealth person.id of the identified patient chosen as the merge target.
     *
     * @var string|null
     */
    public ?string $selectedPersonId = null;

    /**
     * Existing authentication methods of the chosen patient, fetched from eHealth.
     *
     * @var array
     */
    public array $authMethods = [];

    /**
     * The merge request created in eHealth, kept for the subsequent confirmation steps.
     *
     * @var array
     */
    public array $mergeRequest = [];

    /**
     * eHealth ID of the authentication method chosen for the merge.
     *
     * @var string|null
     */
    public ?string $selectedAuthMethodId = null;

    /**
     * Documents eHealth requires for an offline merge, each holding a type and an upload URL.
     *
     * @var array
     */
    public array $uploadedDocuments = [];

    /**
     * Files chosen for upload, keyed by the matching document index in $uploadedDocuments.
     *
     * @var array
     */
    public array $mergeDocuments = [];

    /**
     * Consent document returned by eHealth on approve, shown to the patient and signed in the next step.
     *
     * @var array
     */
    public array $dataToBeSigned = [];

    /**
     * Whether the doctor confirmed that the patient signed the printed consent form; sent to eHealth as
     * patient_signed when signing the merge request.
     *
     * @var bool
     */
    public bool $patientSigned = false;

    /**
     * Initialize the merge search drawer for the given preperson.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function mount(Preperson $preperson): void
    {
        $this->prepersonId = $preperson->id;
        $this->prepersonUuid = $preperson->uuid;

        $this->loadActiveMergeRequest();

        $this->getDictionary();
    }

    /**
     * Load this preperson's active merge request into state so the doctor can resume it from the merge requests
     * table without starting a new one: a NEW request resumes at the confirmation step, an APPROVED one resumes
     * at signing (restoring the consent document persisted on approve).
     *
     * @return void
     */
    private function loadActiveMergeRequest(): void
    {
        $activeMergeRequest = MergeRequest::whereMergePersonId($this->prepersonId)
            ->whereIn('status', [MergeRequestStatus::NEW->value, MergeRequestStatus::APPROVED->value])
            ->latest('ehealth_inserted_at')
            ->first();

        if ($activeMergeRequest === null) {
            return;
        }

        $this->mergeRequest = ['uuid' => $activeMergeRequest->uuid];

        if ($activeMergeRequest->status === MergeRequestStatus::APPROVED) {
            $this->dataToBeSigned = $activeMergeRequest->dataToBeSigned ?? [];
        }
    }

    /**
     * Search eHealth for an identified patient to merge the preperson into.
     *
     * @return void
     */
    public function searchPerson(): void
    {
        if (Auth::user()->cannot('viewAny', Person::class)) {
            Session::flash('error', __('patients.policy.view_any'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForSearch());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $this->mergeSearchPatients = Arr::toCamelCase(
                EHealth::person()->searchForPersonByParams(removeEmptyKeys($validated))->validate()
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for person to merge');

            return;
        }
    }

    /**
     * Store the chosen identified patient and load their existing authentication methods from eHealth.
     *
     * @param  string  $personId
     * @return void
     */
    public function selectPatient(string $personId): void
    {
        if (Auth::user()->cannot('viewAny', Person::class)) {
            Session::flash('error', __('patients.policy.view_any'));

            return;
        }

        $this->selectedPersonId = $personId;

        try {
            $this->authMethods = Arr::toCamelCase(
                EHealth::person()->getAuthMethods($personId)->validate()
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting person authentication methods');

            return;
        }
    }

    /**
     * The authentication method chosen for the merge, resolved from the loaded methods.
     *
     * @return array|null
     */
    #[Computed]
    public function selectedAuthMethod(): ?array
    {
        return collect($this->authMethods)->firstWhere('uuid', $this->selectedAuthMethodId);
    }

    /**
     * Create a request in eHealth to merge the preperson's records into the chosen identified patient.
     *
     * @param  string  $authMethodId
     * @return void
     */
    public function create(string $authMethodId): void
    {
        if (Auth::user()->cannot('create', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.merge'));

            return;
        }

        // Only active prepersons can have their records merged.
        if (Preperson::findOrFail($this->prepersonId)->status !== Status::ACTIVE) {
            Session::flash('error', __('preperson.policy.merge'));

            return;
        }

        $this->selectedAuthMethodId = $authMethodId;

        // eHealth rejects the merge when the preperson has no episodes, so guard it before the request.
        $hasEpisodes = Episode::wherePrepersonId($this->prepersonId)
            ->where('status', '!=', EpisodeStatus::ENTERED_IN_ERROR->value)
            ->exists();

        if (!$hasEpisodes) {
            Session::flash('error', __('preperson.messages.no_episodes'));

            return;
        }

        $payload = [
            'master_person_id' => $this->selectedPersonId,
            'merge_person_id' => $this->prepersonUuid,
            'authorize_with' => $authMethodId
        ];

        try {
            $response = EHealth::mergeRequest()->create($payload);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a merge request');

            return;
        }

        $mergeRequest = $response->validate();

        $masterPersonId = $this->storeMasterPersonLocally();

        try {
            // Cancel any earlier NEW or APPROVED merge requests for this preperson before storing the new one,
            // so only the latest request stays active.
            MergeRequest::whereMergePersonId($this->prepersonId)
                ->whereIn('status', [MergeRequestStatus::NEW->value, MergeRequestStatus::APPROVED->value])
                ->update([
                    'status' => MergeRequestStatus::CANCELLED->value,
                    'data_to_be_signed' => null
                ]);

            MergeRequest::create([
                ...$mergeRequest,
                'master_person_id' => $masterPersonId,
                'merge_person_id' => $this->prepersonId
            ]);
        } catch (Throwable $exception) {
            Session::flash('error', __('messages.database_error'));
            report($exception);

            return;
        }

        // Expose the merge request to the drawer only once it is persisted locally, so the flow never advances
        // (the front-end gate checks $wire.mergeRequest) when we failed to store a record to track its status.
        $this->mergeRequest = $mergeRequest;

        // For an offline merge eHealth returns the documents (with upload URLs) the patient must provide.
        $this->uploadedDocuments = $response->getUrgent()['documents'] ?? [];
    }

    /**
     * Approve the created merge request with the code the patient received via SMS.
     *
     * @param  int  $verificationCode
     * @return bool
     */
    public function approve(int $verificationCode): bool
    {
        if (Auth::user()->cannot('create', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.approve'));

            return false;
        }

        try {
            Validator::make(
                ['verificationCode' => $verificationCode],
                ['verificationCode' => ['required', 'integer']]
            )->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return false;
        }

        return $this->sendApprove(['verification_code' => $verificationCode]);
    }

    /**
     * Upload the documents eHealth requires for an offline merge and then approve the request.
     *
     * @return bool
     */
    public function sendDocuments(): bool
    {
        if (Auth::user()->cannot('create', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.approve'));

            return false;
        }

        try {
            $this->validate(['mergeDocuments.*' => ['required', 'file', 'mimes:jpeg,jpg', 'max:10000']]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return false;
        }

        if (!$this->uploadDocuments()) {
            return false;
        }

        return $this->sendApprove();
    }

    /**
     * Re-send the confirmation code for the merge request to the patient.
     *
     * @return bool
     */
    public function resendOtp(): bool
    {
        if (Auth::user()->cannot('create', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.resend_otp'));

            return false;
        }

        $rateLimitKey = 'resend-merge-otp:' . Auth::id() . ':' . $this->mergeRequest['uuid'];

        // The OTP may be re-sent only once per merge request.
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::OTP_RESEND_LIMIT)) {
            Session::flash('error', __('preperson.messages.otp_already_resent'));

            return false;
        }

        try {
            EHealth::mergeRequest()->resendOtp($this->mergeRequest['uuid']);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when resending the merge request code');

            return false;
        }

        RateLimiter::hit($rateLimitKey);

        Session::flash('success', __('preperson.messages.otp_resent'));

        return true;
    }

    /**
     * Reject the merge request (e.g. the printed consent form has errors) so the doctor can start a new one.
     *
     * @return bool
     */
    public function reject(): bool
    {
        if (Auth::user()->cannot('create', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.reject'));

            return false;
        }

        try {
            $response = EHealth::mergeRequest()->reject($this->mergeRequest['uuid']);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when rejecting a merge request');

            return false;
        }

        try {
            $validated = $response->validate();

            MergeRequest::whereUuid($this->mergeRequest['uuid'])->update([
                'status' => $validated['status'],
                'data_to_be_signed' => null,
                'ehealth_updated_at' => $validated['ehealth_updated_at'],
                'ehealth_updated_by' => $validated['ehealth_updated_by']
            ]);
        } catch (Throwable $exception) {
            Session::flash('error', __('messages.database_error'));
            report($exception);

            return false;
        }

        $this->resetRequest();

        Session::flash('success', __('preperson.messages.merge_rejected'));

        return true;
    }

    /**
     * Sign the approved merge request with the doctor's qualified digital signature and finalize the merge.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('sign', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.sign'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        // The object to sign is the consent document returned by approve; the patient re-reads it and confirms signing.
        $dataToSign = $this->dataToBeSigned;
        $dataToSign['patient_signed'] = $this->patientSigned;

        try {
            $signedContent = new CipherRequest()->signData(
                $dataToSign,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing merge request data with Cipher');

            return;
        }

        try {
            $response = EHealth::mergeRequest()
                ->withHeaders(['msp_drfo' => Auth::user()->party->taxId])
                ->sign($this->mergeRequest['uuid'], [
                    'signed_content' => $signedContent->getBase64Data(),
                    'signed_content_encoding' => 'base64'
                ]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing a merge request');

            return;
        }

        $validated = $response->validate();

        try {
            DB::transaction(function () use ($validated): void {
                MergeRequest::whereUuid($this->mergeRequest['uuid'])->update([
                    'status' => $validated['status'],
                    'data_to_be_signed' => null,
                    'ehealth_updated_at' => $validated['ehealth_updated_at'],
                    'ehealth_updated_by' => $validated['ehealth_updated_by']
                ]);

                // Deactivate the preperson once its records are merged into the identified patient.
                Preperson::whereKey($this->prepersonId)->update(['status' => Status::INACTIVE->value]);
            });
        } catch (Throwable $exception) {
            Session::flash('error', __('messages.database_error'));
            report($exception);

            return;
        }

        Session::flash('success', __('preperson.messages.merge_signed'));
        $this->redirectRoute('prepersons.index', [legalEntity()], navigate: true);
    }

    /**
     * Reset the state tied to a single merge request so the doctor can create a new one from scratch.
     *
     * @return void
     */
    public function resetRequest(): void
    {
        $this->selectedPersonId = null;
        $this->authMethods = [];
        $this->selectedAuthMethodId = null;
        $this->mergeRequest = [];
        $this->uploadedDocuments = [];
        $this->mergeDocuments = [];
        $this->dataToBeSigned = [];
        $this->patientSigned = false;
    }

    /**
     * Clear the merge search filters and results.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->form->firstName = '';
        $this->form->lastName = '';
        $this->form->noLastName = false;
        $this->form->birthDate = '';
        $this->form->secondName = '';
        $this->form->taxId = '';
        $this->form->phoneNumber = '';
        $this->form->documentType = '';
        $this->form->documentNumber = '';

        $this->mergeSearchPatients = [];
    }

    /**
     * Translated label for a document required by the merge request.
     *
     * @param  array  $document
     * @return string
     */
    public function getDocumentLabel(array $document): string
    {
        return __('patients.documents.' . Str::afterLast($document['type'], '.'));
    }

    /**
     * Ensure the chosen identified patient exists in the local persons table and return its local id.
     *
     * @return int|null
     */
    private function storeMasterPersonLocally(): ?int
    {
        $patient = collect($this->mergeSearchPatients)->firstWhere('id', $this->selectedPersonId);

        if (empty($patient)) {
            return null;
        }

        $patient = Arr::toSnakeCase($patient);
        $personFields = Arr::except($patient, ['id', 'names', 'documents', 'phones']);

        try {
            $person = Person::firstOrCreate(['uuid' => $this->selectedPersonId], $personFields);

            if ($person->wasRecentlyCreated) {
                $person->names()->createMany($patient['names']);

                if (!empty($patient['documents'])) {
                    $person->documents()->createMany($patient['documents']);
                }

                if (!empty($patient['phones'])) {
                    $person->phones()->createMany($patient['phones']);
                }
            }

            return $person->id;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Send the approve action for the current merge request to eHealth.
     *
     * @param  array  $data
     * @return bool
     */
    private function sendApprove(array $data = []): bool
    {
        try {
            $response = EHealth::mergeRequest()->approve($this->mergeRequest['uuid'], $data);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving a merge request');

            return false;
        }

        $validated = $response->validate();
        $this->dataToBeSigned = $validated['data_to_be_signed'] ?? [];

        try {
            MergeRequest::whereUuid($this->mergeRequest['uuid'])->update([
                'status' => $validated['status'],
                'data_to_be_signed' => $this->dataToBeSigned,
                'ehealth_updated_at' => $validated['ehealth_updated_at'],
                'ehealth_updated_by' => $validated['ehealth_updated_by']
            ]);
        } catch (Throwable $exception) {
            Session::flash('error', __('messages.database_error'));
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Upload each selected file to the URL eHealth provided for the merge request document.
     *
     * @return bool
     */
    private function uploadDocuments(): bool
    {
        $totalFiles = count($this->uploadedDocuments);

        if (count($this->mergeDocuments) !== $totalFiles) {
            Session::flash('error', __('patients.messages.upload_all_files'));

            return false;
        }

        $successCount = 0;
        foreach ($this->uploadedDocuments as $key => $document) {
            try {
                $file = $this->mergeDocuments[$key];
                $fileMime = $file->getMimeType();
                $fileContents = file_get_contents($file->getRealPath());
                $uploadUrl = trim($document['url']);

                $uploadResponse = Http::withHeaders(['Content-Type' => $fileMime])
                    ->withBody($fileContents, $fileMime)
                    ->put($uploadUrl);

                if ($uploadResponse->successful()) {
                    $successCount++;
                } else {
                    Session::flash('error', __('messages.database_error'));
                }
            } catch (Exception) {
                Session::flash('error', __('messages.database_error'));
            }
        }

        return $successCount === $totalFiles;
    }

    /**
     * Render the merge search drawer.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.preperson.parts.drawers.merge-patients');
    }
}
