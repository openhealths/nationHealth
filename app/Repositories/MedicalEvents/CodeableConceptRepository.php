<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Dto\CodeableConcept\Model as CodeableConceptData;
use App\Models\MedicalEvents\Sql\CodeableConcept as SqlCodeableConcept;
use App\Models\MedicalEvents\Sql\Coding as SqlCoding;
use App\Models\MedicalEvents\Sql\Identifier as SqlIdentifier;
use Illuminate\Database\Eloquent\Collection;

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
     * @param  CodeableConceptData|array  $codeableConceptData
     * @return SqlCodeableConcept
     */
    public function store(CodeableConceptData|array $codeableConceptData): SqlCodeableConcept
    {
        return $this->update(new SqlCodeableConcept(), $codeableConceptData, new Collection());
    }

    /**
     * Update provided instance of codeable concept and its coding.
     *
     * @param  SqlCodeableConcept  $codeableConcept
     * @param  CodeableConceptData|array  $codeableConceptData
     * @param  Collection<int, SqlCoding>|null  $currentCodings  The complete set already fetched for this update.
     * @return SqlCodeableConcept
     */
    public function update(
        SqlCodeableConcept $codeableConcept,
        CodeableConceptData|array $codeableConceptData,
        ?Collection $currentCodings = null
    ): SqlCodeableConcept {
        $attributes = $codeableConceptData instanceof CodeableConceptData
            ? $codeableConceptData->toArray()
            : $codeableConceptData;

        // A partial update can omit text to keep the stored value or pass null to clear it.
        if (array_key_exists('text', $attributes) || !$codeableConcept->exists) {
            $codeableConcept->text = $attributes['text'] ?? null;
        }

        $codeableConcept->save();

        // An omitted coding field preserves the rows. A supplied list replaces them, even when empty.
        if (array_key_exists('coding', $attributes)) {
            $existing = $currentCodings?->sortBy('id')->values() ?? $codeableConcept->coding()->orderBy('id')->get();
            $incoming = array_values($attributes['coding'] ?? []);

            // Match by position in ID order, reusing each row's ID even if its code changes.
            foreach ($incoming as $index => $data) {
                $coding = $existing->get($index) ?? $codeableConcept->coding()->make();
                $coding->fill(['system' => $data['system'] ?? null, 'code' => $data['code']]);
                $coding->save();
            }

            // With existing IDs [10, 20, 30] and two incoming codings, the loop reused IDs 10 and 20.
            // slice(2) leaves only row 30 to delete. An empty slice skips the DELETE query.
            $removed = $existing->slice(count($incoming));
            if ($removed->isNotEmpty()) {
                $codeableConcept->coding()->whereKey($removed->modelKeys())->delete();
            }

            // Reload the relation on its next read so it includes any additions and removals.
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
