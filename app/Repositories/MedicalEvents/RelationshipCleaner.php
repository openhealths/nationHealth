<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\CodeableConcept;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class RelationshipCleaner
{
    /**
     * Clean up identifier-based relationship (identifier -> type -> coding).
     */
    public static function cleanIdentifierRelation(?Model $relation): void
    {
        if (!$relation) {
            return;
        }

        $relation->type->each(function (CodeableConcept $codeableConcept) {
            $codeableConcept->coding()->delete();
        });
        $relation->type()->delete();
        $relation->delete();
    }

    /**
     * Clean up codeable concept relationship (coding directly attached).
     */
    public static function cleanCodeableConceptRelation(?Model $relation): void
    {
        if (!$relation) {
            return;
        }

        $relation->coding()->delete();
        $relation->delete();
    }

    /**
     * Clean up collection of codeable concept relationships.
     */
    public static function cleanCodeableConceptCollection(Collection $collection): void
    {
        foreach ($collection as $item) {
            self::cleanCodeableConceptRelation($item);
        }
    }

    /**
     * Clean up performer/interpreter type relationships (with reference).
     */
    public static function cleanPerformerRelation(?Model $relation): void
    {
        if (!$relation || !$relation->reference) {
            return;
        }

        $relation->reference->type->each(function (CodeableConcept $codeableConcept) {
            $codeableConcept->coding()->delete();
        });
        $relation->reference->type()->delete();
        $relation->reference->delete();
    }

    /**
     * Clean up multiple relationship types in batch.
     */
    public static function cleanRelations(Model $model, array $relationshipMap): void
    {
        foreach ($relationshipMap as $relationName => $type) {
            $relation = $model->{$relationName};

            match ($type) {
                'identifier' => self::cleanIdentifierRelation($relation),
                'codeable_concept' => self::cleanCodeableConceptRelation($relation),
                'codeable_concept_collection' => self::cleanCodeableConceptCollection($relation),
                'performer' => self::cleanPerformerRelation($relation),
                default => null
            };
        }
    }
}
