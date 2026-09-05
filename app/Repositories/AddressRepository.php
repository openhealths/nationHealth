<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Relations\Address;

class AddressRepository
{
    /**
     * Save address to DB using morphTo relation
     *
     * @param  object  $model
     * @param  array  $addresses
     * @return void
     */
    public function addAddresses(object $model, array $addresses): void
    {
        if (!empty($addresses)) {
            foreach ($addresses as $key => $addressData) {
                $address = Address::updateOrCreate(
                    [
                        'addressable_type' => get_class($model),
                        'addressable_id' => $model->id,
                        'type' => $addressData['type']
                    ],
                    $addressData
                );

                $model->addresses()->save($address);
                $model->refresh();
            }
        }
    }

    /**
     * Sync Addresses data to currant ($addresses) state.
     * If $addresses is empty the existent data just will delete.
     *
     * @param  object  $model
     * @param  array  $addresses
     * @return void
     */
    public function syncAddresses(object $model, array $addresses): void
    {
        // Remove all addresses records belongs to the $model
        Address::where([
            'addressable_type' => \get_class($model),
            'addressable_id' => $model->id
        ])
            ->delete();

        if (empty($addresses)) {
            return;
        }

        foreach ($addresses as $key => $addressData) {
            $address = Address::updateOrCreate(
                [
                    'addressable_type' => \get_class($model),
                    'addressable_id' => $model->id,
                    'type' => $addressData['type']
                ],
                $addressData
            );

            $model->addresses()->save($address);
        }
    }
}
