<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Enums\Person\AuthenticationMethod;
use App\Models\Person\Person;
use App\Models\Relations\Phone;

class AuthenticationMethodRepository
{
    /**
     * Sync authentication methods data to the current state.
     * If $authMethods is empty, the existing data will be deleted.
     *
     * @param  Person  $person
     * @param  array  $authMethods
     * @return void
     */
    public function sync(Person $person, array $authMethods): void
    {
        // NA stands for the absence of a method: it carries no identifier and nothing to store
        $authMethods = collect($authMethods)
            ->reject(static fn (array $authMethod): bool => $authMethod['type'] === AuthenticationMethod::NA->value)
            ->values()
            ->toArray();

        $incomingUuids = collect($authMethods)->pluck('uuid')->filter()->values();

        // Delete unrelated (keep only the ones provided) and also delete methods without UUID
        $person->authenticationMethods()
            ->where(function ($query) use ($incomingUuids) {
                $query->whereNotIn('uuid', $incomingUuids)
                    ->orWhereNull('uuid');
            })
            ->delete();

        if (empty($authMethods)) {
            return;
        }

        // Update or create actual by uuid (unique identifier)
        foreach ($authMethods as $method) {
            $person->authenticationMethods()->updateOrCreate(
                ['uuid' => $method['uuid']],
                Arr::only($method, ['uuid', 'type', 'phone_number', 'value', 'alias', 'ehealth_ended_at', 'url'])
            );

            if (!empty($method['confidant_person'])) {
                $this->fillMissingConfidantData($method['value'], $method['confidant_person']);
            }
        }
    }

    /**
     * Fill in what the locally recorded confidant is missing with the data returned along with the method.
     * The values arrive masked, so they never replace the ones of a confidant registered here in full.
     *
     * @param  string  $confidantPersonUuid
     * @param  array  $confidantPersonData
     * @return void
     */
    private function fillMissingConfidantData(string $confidantPersonUuid, array $confidantPersonData): void
    {
        $confidant = Person::whereUuid($confidantPersonUuid)->first();

        if ($confidant === null) {
            return;
        }

        $confidant->update([
            'tax_id' => $confidant->taxId ?? $confidantPersonData['tax_id'] ?? null,
            'unzr' => $confidant->unzr ?? $confidantPersonData['unzr'] ?? null
        ]);

        if (!empty($confidantPersonData['name']) && $confidant->names()->doesntExist()) {
            $confidant->names()->create([
                'language' => 'uk',
                'first_name' => $confidantPersonData['name']
            ]);
        }

        if (!empty($confidantPersonData['documents_person']) && $confidant->documents()->doesntExist()) {
            $confidant->documents()->createMany($confidantPersonData['documents_person']);
        }

        // The phone comes without a type; a confidant always authenticates by SMS, so it is a mobile one
        if (!empty($confidantPersonData['phones']['number']) && $confidant->phones()->doesntExist()) {
            $confidant->phones()->create([
                'type' => Phone::TYPE_MOBILE,
                'number' => $confidantPersonData['phones']['number']
            ]);
        }
    }
}
