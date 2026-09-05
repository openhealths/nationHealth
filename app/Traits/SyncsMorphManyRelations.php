<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Throwable;

trait SyncsMorphManyRelations
{
    /**
     * Synchronizes a morphMany relationship by deleting all existing related models
     * and creating new ones from the provided data. The entire operation is atomic.
     * It intelligently handles a single record (associative array) or multiple records (array of arrays).
     * If the provided data is null, the method does nothing.
     *
     * @param  string  $relation  The name of the morphMany relationship method (e.g., 'educations').
     * @param  array|null  $data  The data to sync.
     * @return void
     * @throws Throwable
     */
    public function syncMany(string $relation, ?array $data): void
    {
        if (is_null($data)) {
            return;
        }

        if (!empty($data) && !array_is_list($data)) {
            $dataToCreate = [$data];
        } else {
            $dataToCreate = $data;
        }

        DB::transaction(function () use ($relation, $dataToCreate) {
            $this->$relation()->delete();

            if (!empty($dataToCreate)) {
                $this->$relation()->createMany($dataToCreate);
            }
        });
    }
}
