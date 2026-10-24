<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Identifier as SqlIdentifier;

/** @property SqlIdentifier $model  */
class IdentifierRepository extends BaseRepository
{
    /** Persist an owned reference, including all type codings. */
    public function replaceReference(?SqlIdentifier $identifier, array $data): SqlIdentifier
    {
        $identifier ??= new SqlIdentifier();
        $identifier->value = $data['identifier']['value'];
        $identifier->displayValue = $data['displayValue'] ?? null;
        $identifier->save();
        $type = $identifier->type()->first();
        if (isset($data['identifier']['type'])) {
            $type = Repository::codeableConcept()->replaceComplete($type, $data['identifier']['type']);
            $identifier->type()->save($type);
        } elseif ($type) {
            Repository::codeableConcept()->delete($type);
        }

        return $identifier;
    }

    /** The caller must eager-load type.coding. */
    public function toReference(SqlIdentifier $identifier): array
    {
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
     * Create identifier in DB.
     *
     * @param  string  $value
     * @param  string|null  $displayValue
     * @return SqlIdentifier
     */
    public function store(string $value, ?string $displayValue = null): SqlIdentifier
    {
        return $this->model->create([
            'value' => $value,
            'display_value' => $displayValue
        ]);
    }
}
