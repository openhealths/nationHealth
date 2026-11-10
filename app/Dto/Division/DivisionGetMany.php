<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate;
use App\Models\Division;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
class DivisionGetMany
{
    /** @var list<Model> */
    #[Map(source: '[divisions]', transform: [[self::class, 'wrapDivisions'], new MapCollection(targetClass: Model::class)])]
    public array $divisions = [];

    /**
     * @param  iterable<array<string, mixed>|DivisionCreate>  $divisions
     * @return list<DivisionCreate>
     */
    public static function wrapDivisions(iterable $divisions): array
    {
        $items = [];
        foreach ($divisions as $division) {
            $items[] = $division instanceof DivisionCreate ? $division : new DivisionCreate($division);
        }

        return $items;
    }

    public function isEmpty(): bool
    {
        return $this->divisions === [];
    }

    /** @return list<string> */
    public function uuids(): array
    {
        return array_map(fn (Model $division): string => $division->uuid, $this->divisions);
    }

    /** @return list<array<string, mixed>> */
    public function toUpsertRows(int $legalEntityId): array
    {
        $rows = [];
        foreach ($this->divisions as $division) {
            $row = $division->toModelAttributes();
            $row['legal_entity_id'] = $legalEntityId;
            $row['location'] = empty($division->location)
                ? null
                : json_encode($division->location, JSON_THROW_ON_ERROR);
            $row['working_hours'] = empty($division->workingHours)
                ? null
                : json_encode($division->workingHours, JSON_THROW_ON_ERROR);

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $divisionIds  UUIDs mapped to local primary keys.
     * @return array{addresses: list<array<string, mixed>>, phones: list<array<string, mixed>>}
     */
    public function toRelationshipRows(array $divisionIds, string $timestamp): array
    {
        $addresses = [];
        $phones = [];

        foreach ($this->divisions as $division) {
            $divisionId = $divisionIds[$division->uuid];

            foreach ($division->addresses as $address) {
                $addresses[] = $address->toArray() + [
                    'addressable_id' => $divisionId,
                    'addressable_type' => Division::class,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach ($division->phones as $phone) {
                $phones[] = $phone->toArray() + [
                    'phoneable_id' => $divisionId,
                    'phoneable_type' => Division::class,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        return ['addresses' => $addresses, 'phones' => $phones];
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (Model $division): array => $division->toArray(), $this->divisions);
    }
}
