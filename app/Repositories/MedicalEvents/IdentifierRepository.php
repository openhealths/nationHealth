<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Dto\Identifier\Model as IdentifierData;
use App\Models\MedicalEvents\Sql\Identifier as SqlIdentifier;

/** @property SqlIdentifier $model  */
class IdentifierRepository extends BaseRepository
{
    /**
     * Update the identifier and its type. An identifier DTO carries no display value,
     * so a stored one, which described the previous value, is cleared.
     *
     * @param  SqlIdentifier  $identifier
     * @param  IdentifierData|array  $data
     * @return SqlIdentifier
     */
    public function update(SqlIdentifier $identifier, IdentifierData|array $data): SqlIdentifier
    {
        if ($data instanceof IdentifierData) {
            $identifier->fill(['value' => $data->value, 'display_value' => null])->save();

            $type = $identifier->type()->first();
            if ($type === null) {
                $identifier->type()->save(Repository::codeableConcept()->store($data->type));
            } else {
                Repository::codeableConcept()->update($type, $data->type);
            }
            $identifier->unsetRelation('type');

            return $identifier;
        }

        $identifier->fill([
            'value' => $data['identifier']['value'],
            'display_value' => $data['displayValue'] ?? $data['display_value'] ?? null,
        ])->save();
        if (isset($data['identifier']['type'])) {
            Repository::codeableConcept()->attach($identifier, $data);
        } else {
            foreach ($identifier->type()->get() as $concept) {
                Repository::codeableConcept()->delete($concept);
            }
        }
        $identifier->unsetRelation('type');

        return $identifier;
    }

    /** The caller must eager-load type.coding. */
    public function toReference(?SqlIdentifier $identifier): ?array
    {
        if ($identifier === null) {
            return null;
        }

        $data = ['identifier' => ['value' => $identifier->value]];
        $type = $identifier->getRelation('type')->first();
        if ($type) {
            $data['identifier']['type'] = Repository::codeableConcept()->toResource($type);
        }
        if ($identifier->displayValue !== null) {
            $data['displayValue'] = $identifier->displayValue;
        }

        return $data;
    }

    /**
     * Create identifier in DB. An identifier DTO is stored together with its type.
     *
     * @param  IdentifierData|string  $value
     * @param  string|null  $displayValue
     * @return SqlIdentifier
     */
    public function store(IdentifierData|string $value, ?string $displayValue = null): SqlIdentifier
    {
        if ($value instanceof IdentifierData) {
            $identifier = $this->model->create(['value' => $value->value]);
            $identifier->type()->save(Repository::codeableConcept()->store($value->type));

            return $identifier;
        }

        return $this->model->create([
            'value' => $value,
            'display_value' => $displayValue
        ]);
    }

}
