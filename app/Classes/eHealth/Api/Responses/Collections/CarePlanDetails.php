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
    /** The native resource, kept intact for signed snapshots. */
    public array $native;

    public function __construct(iterable|null $items = [], ?array $native = null)
    {
        parent::__construct($items);
        $this->native = $native ?? $this->items;

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

    /** Convert validated data for legacy consumers that still use normalized resource arrays. */
    public function toResource(): array
    {
        return Arr::toCamelCase(array_replace($this->native, ['status' => $this->status()->value]));
    }
}
