<?php

declare(strict_types=1);

namespace App\Livewire\LegalEntity;

use Arr;
use Throwable;
use App\Models\User;
use App\Enums\JobStatus;
use App\Traits\FormTrait;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use App\Repositories\PhoneRepository;
use App\Notifications\SyncNotification;
use Illuminate\Support\Facades\Session;
use App\Repositories\AddressRepository;
use App\Traits\BatchLegalEntityQueries;
use Spatie\Permission\PermissionRegistrar;
use App\Enums\License\Type as LicenseType;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\LegalEntity\LegalEntity as LegalEntityComponent;

class LegalEntityDetails extends LegalEntityComponent
{
    use BatchLegalEntityQueries;
    use FormTrait;

    protected const string BATCH_NAME = 'FirstLoginSync';

    public array $edrStatuses = [];

    public array $edrLegalForms = [];

    public array $mainKVED = [];

    public array $additionalKVEDs = [];

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    /**
     * Get the synchronization status of the declarations
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()?->getEntityStatus() ?? '';
    }

    #[Computed]
    public function isSync(): bool
    {
        return $this->isSyncProcessing();
    }

    /**
     * Determine if a synchronization process is currently running.
     *
     * @return bool True if a sync process is actively processing, false otherwise.
     */
    protected function isSyncProcessing(): bool
    {
        // Get the sync status for whole Legal Entity
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        return $this->isEntitySyncIsInProgress($this->syncStatus);
    }

    public function boot(
        AddressRepository $addressRepository,
        PhoneRepository $phoneRepository
    ): void {
        parent::boot($addressRepository, $phoneRepository);

        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
    }

    public function mount(?LegalEntity $legalEntity = null): void
    {
        $this->legalEntity = $this->getLegalEntity();

        parent::mount();

        $this->getLegalEntityForm();

        $this->edrStatuses = dictionary()->basics()->byName('EDR_STATE')->asCodeDescription()->toArray();
        $this->edrLegalForms = dictionary()->basics()->byName('LEGAL_FORM')->asCodeDescription()->toArray();

        $this->filterKveds();

        // Get the sync status for whole Legal Entity
        $this->syncStatus = $this->getSyncStatus();
    }

    /**
     * Try to get the LegalEntity assigned for the user
     *
     * @return LegalEntity|null
     */
    protected function getLegalEntity(): ?LegalEntity
    {
        return legalEntity()?->loadMissing(['licenses', 'addresses', 'phones', 'revisions']) ?? null;
    }

    protected function setLegalEntity(): bool
    {
        $isNotNew = parent::setLegalEntity();

        if ($isNotNew) {
            $address = data_get($this->legalEntity->toArray(), 'addresses.0', []);

            $this->mergeAddress($this->convertArrayKeysToCamelCase($address));
        }

        return $isNotNew;
    }

    /**
      * Retrieves the legal entity form data.
      */
    protected function getLegalEntityForm(): void
    {
        $this->setLegalEntity(); // Retrieve basic legal entity data
        $this->getLicenseForm(); // Get the license form data
        $this->getArchiveForm(); // Get the archive form data
        $this->getOwnerLegalEntity(); // Get the owner's legal entity data
        $this->getAccreditationForm(); // Get the accreditation form data status
        $this->getBeneficiaryForm(); // Get the beneficiary form data status
        $this->getReceiverFundsCodeForm(); // Get the receiver funds code form data status

        $this->legalEntityForm->residenceAddress = $this->address;
    }

    /**
     * Retrieves and sets only specific fields related to the license from the legal entity form.
     */
    protected function getLicenseForm(): void
    {
        $licenses = $this->legalEntity->licenses()->get();

        $license = $licenses->filter(function ($item) {
            return $item->type->name === LicenseType::MSP->value || $item->type->name === LicenseType::PHARMACY->value;
        })->first();

        if ($license) {
            $this->legalEntityForm->license = Arr::only(
                $this->convertArrayKeysToCamelCase($license->toArray()),
                [
                    'type',
                    'licenseNumber',
                    'issuedBy',
                    'issuedDate',
                    'expiryDate',
                    'activeFromDate',
                    'whatLicensed',
                    'orderNo'
                ]
            );
        }
    }

    /**
     * Retrieves and formats specific fields from the archive form.
     */
    protected function getArchiveForm(): void
    {
        // Extracting only 'date' and 'place' fields from the first element of the archive
        if (!empty($this->legalEntityForm->archive)) {
            // if the legal entity has an archive, the 'archivationShow' property is set to true
            $this->legalEntityForm->archivationShow = true;
        }
    }

    /**
     * Get the accreditation status of the legal entity
     * (if the legal entity has an accreditation, the 'accreditationShow' property is set to true)
     *
     * @return void
     */
    protected function getAccreditationForm(): void
    {
        if (!empty($this->legalEntityForm->accreditation) && $this->legalEntityForm->accreditation['category'] !== null) {
            $this->legalEntityForm->accreditationShow = true;
        }
    }

    /**
     * If the legal entity has an beneficiary, the 'beneficiaryShow' property is set to true
     */
    protected function getBeneficiaryForm(): void
    {
        if (!empty($this->legalEntityForm->beneficiary)) {
            $this->legalEntityForm->beneficiaryShow = true;
        }
    }

    /**
     * If the legal entity has an beneficiary, the 'receiverFundsCodeShow' property is set to true
     */
    protected function getReceiverFundsCodeForm(): void
    {
        if (!empty($this->legalEntityForm->receiverFundsCode)) {
            $this->legalEntityForm->receiverFundsCodeShow = true;
        }
    }

    /**
     * Retrieves and sets the owner legal entity for the current legal entity.
     *
     * @return void
     */
    protected function getOwnerLegalEntity(): void
    {
        $owner = $this->legalEntity->getOwner();

        if (!$owner->exists()) {
            return;
        }

        $partyUsers = $owner->party->users()->get();
        $ownerData = $owner->party->toArray() ?? [];

        $ownerData['phones'] = $owner->party->phones->toArray() ?? [];
        $ownerData['documents'] = $this->prepareDocumentsData($owner->party->documents->toArray());
        $ownerData['position'] = $owner->position;
        $ownerData['employee_id'] = $owner->uuid;

        // Return or email user logined (if it has OWNER role) or first email attached to the employee with OWNER role
        $ownerData['email'] = $partyUsers
            ->where('email', Auth::user()->email)
            ->first()?->email ?? $partyUsers->first()?->email;

        // TODO: remove it when all other entity will use the same date format
        $ownerData['birthDate'] = convertToAppDateFormat($ownerData['birthDate']);

        $this->legalEntityForm->owner = array_merge($this->legalEntityForm->owner ?? [], $ownerData);
    }

    /**
     * Prepare documents data for display or processing.
     *
     * @param  array  $documents  The raw documents data to be prepared
     * @return array
     */
    private function prepareDocumentsData(array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        // TODO: remove it when all other entity will use the same date format
        // $documents[0]['issuedAt'] = Carbon::parse($documents[0]['issuedAt'])->format(config('app.date_format'));

        return $this->convertArrayKeysToCamelCase($documents[0]);
    }

    /**
    * Filters the KVED (Classification of Types of Economic Activities) codes.
    * This method processes and filters the collection of KVED codes associated
    *
     * @return void
    */
    protected function filterKveds(): void
    {
        $mainKved = [];
        $additionalKveds = [];

        foreach ($this->legalEntity->edr['kveds'] as $kved) {
            $kvedArr = ['code' => $kved['code'], 'name' => $kved['name']];

            if (data_get($kved, 'is_primary')) {
                $mainKved = $kvedArr;
            } else {
                $additionalKveds[] = $kvedArr;
            }
        }

        $this->mainKVED = $this->convertArrayKeysToCamelCase($mainKved);
        $this->additionalKVEDs = $this->convertArrayKeysToCamelCase($additionalKveds);
    }

    /**
     * Synchronizes the legal entity data.
     *
     * @return void
     */
    public function sync(): void
    {
        /*
         * This is need by Livewire behavior.
         * On the first render, mount() runs and assigns $this->legalEntity.
         * On subsequent requests (e.g., when clicking synchronize button), Livewire does NOT run mount() again
         * and does NOT rehydrate protected typed properties.
         * Code below allows to ensure that property is set before use.
         */
        $this->legalEntity ??= $this->getLegalEntity();

        if (Auth::user()->cannot('sync', $this->legalEntity)) {
            session()->flash('error', __('legal-entity.policy.deny.sync'));

            return;
        }

        if ($this->isSyncProcessing()) {
            Session::flash('error', 'Синхронізація вже запущена. Будь ласка, зачекайте її завершення.');

            return;
        }

        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('Відновлення попередньої синхронізації розпочато'));

            $user->notify(new SyncNotification('legal_entity', 'resumed'));

            return;
        }

        legalEntity()?->setEntityStatus(JobStatus::PROCESSING);

        $oldStatus = $this->legalEntity->status;

        try {
            $response = EHealth::legalEntity()->getDetails();

            $legalEntityData = $this->normalizeDate(['data' => $response->validate()]);

            // Set accreditation and archive to null concerns on the storda data in the DB table
            $legalEntityData = $this->filterUnprovidedFields($legalEntityData, $this->legalEntityForm->toArray());

            $this->modifyLegalEntity($legalEntityData);
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncLegalEntity', ['error' => $err->getMessage()]);

            session()->flash('error', __('errors.ehealth.messages.server_error'));

            legalEntity()?->setEntityStatus(JobStatus::FAILED);

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncLegalEntity', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.server_error'));

            legalEntity()?->setEntityStatus(JobStatus::FAILED);

            return;
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(static::class . ': [syncLegalEntity]: ', ['error' => $err->getMessage()]);

            session()->flash('error', __('legal-entity.request.sync.errors.fail'));

            legalEntity()?->setEntityStatus(JobStatus::FAILED);

            return;
        }

        if ($legalEntityData['data']['status'] !== $oldStatus) {
            Log::channel('e_health_warnings')->warning(
                static::class . ': [syncLegalEntity]: Legal Entity type changed',
                [
                    'legal_entity_uuid' => $this->legalEntity->uuid,
                    'old_status' => $oldStatus,
                    'new_status' => $legalEntityData['data']['status'],
                ]
            );

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            Auth::user()->unsetRelation('roles')->unsetRelation('permissions');

            Auth::user()->syncPermissions(Auth::user()->getAllPermissions()->pluck('name')->toArray());
        }

        $this->redirect(route('legal-entity.details', [legalEntity()]), navigate: true);

        session()->flash('success', __('forms.update_successfull'));

        legalEntity()?->setEntityStatus(JobStatus::COMPLETED);

        return;
    }

    /**
    * Resume the synchronization process for a user with the provided token.
    *
    * This method handles the continuation of a previously initiated synchronization
    * operation for a specific user using an authentication or session token.
    *
     * @param  User  $user  The user instance for whom synchronization should be resumed
     * @param  string  $token  The authentication or session token used to resume the sync process
     * @return void
    */
    protected function resumeSynchronization(User $user, string $token): void
    {
        $encryptedToken = Crypt::encryptString($token);

        // Find all the Equipment's failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME) {
                Log::info('Resuming Equipment sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    public function render()
    {
        return view('livewire.legal-entity.legal-entity-details');
    }
}
