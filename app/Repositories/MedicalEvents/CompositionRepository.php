<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionExtension;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;

class CompositionRepository extends BaseRepository
{
    public function __construct(Composition $model)
    {
        parent::__construct($model);
    }

    public function store(array $details, Person|Preperson $patient, ?string $episodeUuid = null): ?Composition
    {
        $uuid = data_get($details, 'identifier.value');
        if (!$uuid) {
            return null;
        }

        return DB::transaction(function () use ($details, $patient, $episodeUuid, $uuid): Composition {
            $composition = $this->model->newQuery()->firstOrNew(['uuid' => $uuid]);
            [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

            $attributes = [
                $ownerColumn => $ownerId,
                $ownerColumn === 'person_id' ? 'preperson_id' : 'person_id' => null,
                'ehealth_updated_at' => now(),
            ];

            foreach (['status', 'title', 'date'] as $field) {
                if (array_key_exists($field, $details)) {
                    $attributes[$field] = $field === 'status'
                        ? CompositionStatus::fromEHealth($details[$field])->value
                        : $details[$field];
                }
            }

            if (array_key_exists('extension', $details)) {
                $extensions = collect($details['extension'] ?? [])->keyBy('valueCode');
                foreach (CompositionExtension::cases() as $field) {
                    $value = data_get($extensions->get($field->value), $field->valueKey());
                    $attributes[$field->column()] = $field->valueKey() === 'valueBoolean' ? (bool) $value : $value;
                }
            }

            foreach (['type', 'category'] as $field) {
                if (isset($details[$field])) {
                    $concept = $details[$field];
                    $concept['coding'][0]['system'] ??= $field === 'type' ? 'COMPOSITION_TYPES' : 'COMPOSITION_CATEGORIES';
                    $attributes[$field . '_id'] = $this->syncCodeableConcept(
                        $composition->exists ? $composition : null,
                        $concept,
                        $field . 'Concept'
                    )?->id;
                }
            }

            $references = [
                'encounter' => 'encounter',
                'author' => 'author',
                'custodian' => 'custodian',
                'subject' => 'subject',
                'section_focus' => 'section.focus',
            ];
            foreach ($references as $column => $path) {
                $reference = data_get($details, $path);
                if ($reference !== null) {
                    $attributes[$column . '_id'] = $this->storeIdentifier($reference, $composition->getAttribute($column . '_id'));
                }
            }

            $episodeUuid ??= data_get($details, 'episodeOfCare.value');
            if ($episodeUuid) {
                $attributes['episode_of_care_id'] = $this->storeIdentifier(['value' => $episodeUuid], $composition->episodeOfCareId);
            }
            if (array_key_exists('relatesTo', $details)) {
                $attributes['relates_to_code'] = data_get($details, 'relatesTo.code');
                $attributes['relates_to_target_id'] = $this->storeIdentifier(data_get($details, 'relatesTo.targetIdentifier'), $composition->relatesToTargetId);
            }

            $composition->fill($attributes)->save();
            $period = data_get($details, 'event.0.period');
            if (is_array($period)) {
                Repository::period()->sync($composition, $period, 'eventPeriod');
            }

            return $composition->refresh();
        });
    }

    public function storeIntegration(Composition $composition, array $items): void
    {
        DB::transaction(function () use ($composition, $items): void {
            $composition->newQuery()->whereKey($composition->id)->lockForUpdate()->firstOrFail();
            $composition->integrations()->delete();
            foreach ($items as $item) {
                $composition->integrations()->create([
                    'component' => $item['component'],
                    'type' => $item['type'],
                    'integration_status' => $item['integrationStatus'] ?? null,
                    'task_status' => $item['taskStatus'] ?? null,
                    'record_number' => data_get($item, 'details.SL_NUM'),
                    'status_message' => $item['statusMessage'] ?? null,
                    'ehealth_updated_at' => $item['updatedAt'] ?? null,
                ]);
            }
        });
        $composition->unsetRelation('integrations');
    }

    private function storeIdentifier(mixed $reference, ?int $existingId = null): ?int
    {
        $value = data_get($reference, 'value');

        if (!is_string($value) || $value === '') {
            return null;
        }

        if ($existingId !== null) {
            $existing = Identifier::find($existingId);
            if ($existing?->value === $value) {
                if (is_array(data_get($reference, 'type'))) {
                    Repository::codeableConcept()->attach($existing, ['identifier' => $reference]);
                }

                return $existingId;
            }
        }

        $identifier = Repository::identifier()->store($value);
        $type = data_get($reference, 'type');

        if (is_array($type)) {
            Repository::codeableConcept()->attach($identifier, [
                'identifier' => ['type' => $type],
            ]);
        }

        return $identifier->id;
    }
}
