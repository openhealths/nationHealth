<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * This job is responsible for finalizing a full synchronization operation between different data sources
 *
 * @package App\Jobs
 */
class LegalEntityDetailsSync extends EHealthJob
{
    public const string BATCH_NAME = 'LegalEntitySync';

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

        unset($data['type']);

        $oldStatus = $this->legalEntity->status;

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

        // Save or update the object in the database
        $this->legalEntity->save();

        Repository::address()->syncAddresses($this->legalEntity, $addressData);

        Repository::phone()->syncPhones($this->legalEntity, $phones);

        $this->legalEntity->refresh();

        Repository::legalEntity()->saveLicense($license, $this->legalEntity);

        if ($data['status'] !== $oldStatus) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->user->unsetRelation('roles')->unsetRelation('permissions');

            $this->user->syncPermissions($this->user->getAllPermissions()->pluck('name')->toArray());
        }

        $this->sendEntityNotification('legal_entity', 'completed');
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
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
