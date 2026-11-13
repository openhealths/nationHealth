<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\CodeableConcept as SqlCodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier as SqlIdentifier;

class CodeableConceptRepository extends BaseRepository
{
    /**
     * Codeable concept as an array of its coding (system and code) and text, null when the concept is absent.
     * Text is included only when set. The caller must eager-load coding; serialization itself performs no query.
     *
     * @param  SqlCodeableConcept|null  $concept
     * @return array|null
     */
    public function toResource(?SqlCodeableConcept $concept): ?array
    {
        if ($concept === null) {
            return null;
        }

        $data = ['coding' => []];
        if ($concept->text !== null) {
            $data['text'] = $concept->text;
        }

        foreach ($concept->getRelation('coding')->sortBy('id') as $coding) {
            $data['coding'][] = ['system' => $coding->system, 'code' => $coding->code];
        }

        return $data;
    }

    /**
     * Create codeable concept in DB by provided data and attach coding.
     *
     * @param  array  $codeableConceptData
     * @return SqlCodeableConcept
     */
    public function store(array $codeableConceptData): SqlCodeableConcept
    {
        $concept = new SqlCodeableConcept();

        return $this->update($concept, $codeableConceptData);
    }

    /**
     * Update provided instance of codeable concept and its coding.
     *
     * @param  SqlCodeableConcept  $codeableConcept
     * @param  array  $codeableConceptData
     * @return SqlCodeableConcept
     */
    public function update(SqlCodeableConcept $codeableConcept, array $codeableConceptData): SqlCodeableConcept
    {
        if (array_key_exists('text', $codeableConceptData) || !$codeableConcept->exists) {
            $codeableConcept->text = $codeableConceptData['text'] ?? null;
        }

        $codeableConcept->save();

        if (array_key_exists('coding', $codeableConceptData)) {
            $existing = $codeableConcept->relationLoaded('coding')
                ? $codeableConcept->coding->sortBy('id')->values()
                : $codeableConcept->coding()->orderBy('id')->get();
            $ids = [];

            foreach (array_values($codeableConceptData['coding'] ?? []) as $index => $data) {
                $coding = $existing->get($index) ?? $codeableConcept->coding()->make();
                $coding->fill(['system' => $data['system'] ?? null, 'code' => $data['code']]);
                $coding->save();
                $ids[] = $coding->id;
            }

            if ($existing->count() > count($ids)) {
                $codeableConcept->coding()->whereNotIn('id', $ids)->delete();
            }

            $codeableConcept->unsetRelation('coding');
        }

        return $codeableConcept;
    }

    /**
     * Create codeable concept in DB for identifier.
     *
     * @param  SqlIdentifier  $identifier
     * @param  array  $codeableConceptData
     * @return SqlCodeableConcept
     */
    public function attach(SqlIdentifier $identifier, array $codeableConceptData): SqlCodeableConcept
    {
        $concept = $identifier->type()->first() ?? new SqlCodeableConcept();
        $this->update($concept, $codeableConceptData['identifier']['type']);
        $identifier->type()->save($concept);

        return $concept;
    }

    /**
     * Delete codeable concept and its coding.
     *
     * @param  SqlCodeableConcept  $codeableConcept
     * @return bool
     */
    public function delete(SqlCodeableConcept $codeableConcept): bool
    {
        $codeableConcept->coding()->delete();

        return $codeableConcept->delete();
    }
}
