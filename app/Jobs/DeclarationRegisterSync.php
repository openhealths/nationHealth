<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Models\User;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;

class DeclarationRegisterSync extends DeclarationsSync
{
    public const string BATCH_NAME = 'DeclarationRegisterSync';

    protected function getSessionToken(User $user): string
    {
        // A replacement token for this facility-wide query must also belong to a register user.
        return $user->usesFacilityDeclarationRegister('ehealth') ? parent::getSessionToken($user) : '';
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::declaration()->withToken($token)->getRegisterPage($this->legalEntity->uuid, $this->page);
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        Repository::declaration()->storeRegisterPage($response->validate(), $this->legalEntity);
    }

    protected function getNextEntityJob(): EHealthJob
    {
        return $this->nextEntity ?? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin);
    }
}
