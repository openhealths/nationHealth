<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use App\Core\Arr;
use App\Enums\CarePlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Validated native CarePlan data; nested concepts use the shared mapper inputs.
 *
 * @extends Collection<string, mixed>
 */
class CarePlanDetails extends Collection
{
    /** Validated API fields before wrapping nested values for ObjectMapper. */
    protected array $validated;

    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);
        $this->validated = $this->items;

        foreach (['category', 'terms_of_service', 'status_reason'] as $field) {
            if (is_array($this->get($field))) {
                $this->put($field, new EhealthCodeableConcept($this->get($field)));
            }
        }
        if ($this->has('addresses')) {
            $this->put('addresses', array_map(
                static fn (array|EhealthCodeableConcept $item): EhealthCodeableConcept => $item instanceof EhealthCodeableConcept
                    ? $item
                    : new EhealthCodeableConcept($item),
                $this->get('addresses')
            ));
        }
    }

    public function status(): CarePlanStatus
    {
        return $this->get('status') === 'cencelled'
            ? CarePlanStatus::CANCELLED
            : CarePlanStatus::fromStored($this->get('status'));
    }

    public function statusReason(): ?EhealthCodeableConcept
    {
        $reason = $this->get('status_reason');
        if ($reason instanceof EhealthCodeableConcept) {
            return $reason;
        }

        $history = collect($this->get('status_history', []))
            ->filter(fn (array $entry): bool => $entry['status'] === $this->get('status'))
            ->sortByDesc(static fn (array $entry): int => CarbonImmutable::parse($entry['inserted_at'])->getTimestamp())
            ->first();

        return isset($history['status_reason']) ? new EhealthCodeableConcept($history['status_reason']) : null;
    }

    /** Return the validated fields in the application’s normalized resource format. */
    public function toResource(): array
    {
        return Arr::toCamelCase(array_replace($this->validated, ['status' => $this->status()->value]));
    }
}
