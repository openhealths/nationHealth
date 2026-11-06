<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Arr;
use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Queue\Middleware\RateLimited;
use App\Listeners\Synchronization\FirstLoginSyncChain;

/**
 * This job is responsible for finalizing a full synchronization operation between different data sources
 *
 * @package App\Jobs
 */
class LegalEntityDetailsSync extends EHealthJob
{
    public const string BATCH_NAME = 'LegalEntitySync';

    public function __construct(
        ?LegalEntity $legalEntity = null,
        ?EHealthJob $nextEntity = null,
        bool $isFirstLogin = false,
        int $page = 1,
        bool $standalone = false,
        protected array $scopes = []
    ) {
        parent::__construct($legalEntity, $nextEntity, $isFirstLogin, $page, $standalone);
    }

    // Get data from EHealth API (here it mostly dummy method)
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::legalEntity()
            ->withToken($token)
            ->getDetails(uuid: $this->legalEntity->uuid);
    }

    // Store or update data in the database (here it mostly dummy method)
    protected function processResponse(?EHealthResponse $response): void
    {
        $data = $response->validate();

        setPermissionsTeamId($this->legalEntity->id);
        Auth::shouldUse('ehealth');

        $oldStatus = $this->legalEntity->status;
        $oldTypeId = $this->legalEntity->legal_entity_type_id;
        $type = LegalEntityType::whereName(Arr::pull($data, 'type'))->firstOrFail();

        // This need because the LegalEntity has a separate table for the address
        $addressData = [Arr::pull($data, 'residence_address', [])];

        // This need because the LegalEntity has a separate table for the phones
        $phones = Arr::pull($data, 'phones', []);

        $license = Arr::pull($data, 'license', []);

        // Normalize date fields (need for MySQL date format)
        if (isset($data['inserted_at'])) {
            $data['inserted_at'] = convertToYmd($data['inserted_at']);
        }

        if (isset($data['updated_at'])) {
            $data['updated_at'] = convertToYmd($data['updated_at']);
        }

        // Fill the object with data
        $this->legalEntity->fill($data);
        $this->legalEntity->type()->associate($type);

        // Save or update the object in the database
        $this->legalEntity->save();

        Repository::address()->syncAddresses($this->legalEntity, $addressData);

        Repository::phone()->syncPhones($this->legalEntity, $phones);

        $this->legalEntity->refresh();

        if (!empty($license)) {
            // A new connection imports the eHealth state without preserving earlier local choices.
            Repository::legalEntity()->saveLicense($license, $this->legalEntity, preserveEmptyActivity: false);
        }

        if ($data['status'] !== $oldStatus || $this->legalEntity->legal_entity_type_id !== $oldTypeId) {
            cache()->memo()->forget('le_type:' . $this->legalEntity->id);
            cache()->memo()->forget('allowed_permissions:' . $this->legalEntity->id . ':ehealth');

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->user->unsetRelation('roles')->unsetRelation('permissions');

            // Reapply granted token scopes through the refreshed role/type filters without promoting inherited permissions.
            $this->user->syncPermissions($this->scopes);
        }
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('legal-entity-legal-entity-by-id')
        ];
    }

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        // Synchronize the next entities chain (first login) if needed
        if ($this->isFirstLogin && !$this->standalone && !$this->nextEntity) {
            return app(FirstLoginSyncChain::class)->build($this->legalEntity, $this->scopes);
        }

        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
