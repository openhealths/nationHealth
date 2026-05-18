<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\CodeableConcept as SqlCodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier as SqlIdentifier;
use InvalidArgumentException;

class CodeableConceptRepository extends BaseRepository
{
    /**
     * Create codeable concept in DB by provided data and attach coding.
     *
     * @param  array  $codeableConceptData
     * @return SqlCodeableConcept
     */
    public function store(array $codeableConceptData): SqlCodeableConcept
    {
        $codeableConcept = $this->model::create([
            'text' => $codeableConceptData['text'] ?? null
        ]);

        $codeableConcept->coding()->create([
            'system' => $codeableConceptData['coding'][0]['system'],
            'code' => $codeableConceptData['coding'][0]['code']
        ]);

        return $codeableConcept;
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
        // Update text
        $codeableConcept->update([
            'text' => $codeableConceptData['text'] ?? $codeableConcept->text
        ]);

        // Update existed or create
        if (!empty($codeableConceptData['coding'])) {
            $coding = $codeableConcept->coding->first();

            if ($coding) {
                $coding->update([
                    'system' => $codeableConceptData['coding'][0]['system'],
                    'code' => $codeableConceptData['coding'][0]['code']
                ]);
            } else {
                $codeableConcept->coding()->create([
                    'system' => $codeableConceptData['coding'][0]['system'],
                    'code' => $codeableConceptData['coding'][0]['code']
                ]);
            }
        }

        return $codeableConcept;
    }

    /**
     * Update related codeable concept by ID.
     *
     * @param  int  $id
     * @param  array  $data
     * @return mixed
     */
    public function updateById(int $id, array $data)
    {
        if (empty($data) || empty($data['coding'][0]['code'])) {
            throw new InvalidArgumentException("Invalid CodeableConcept data for update.");
        }

        $concept = $this->model::findOrFail($id);

        $codingData = $data['coding'][0];
        $text = $data['text'] ?? null;

        $concept->update(['text' => $text]);

        $concept->coding()->update([
            'code' => $codingData['code'],
            'system' => $codingData['system'] ?? null
        ]);

        return $concept;
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
        if (!$identifier->wasRecentlyCreated) {
            $existing = $identifier->type()->first();
            if ($existing) {
                return $existing;
            }
        }

        $codeableConcept = $identifier->type()->create([
            'text' => $codeableConceptData['identifier']['type']['text'] ?? null
        ]);

        $codeableConcept->coding()->create([
            'system' => $codeableConceptData['identifier']['type']['coding'][0]['system'],
            'code' => $codeableConceptData['identifier']['type']['coding'][0]['code']
        ]);

        return $codeableConcept;
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
