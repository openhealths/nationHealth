<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated data of the job eHealth has created to process a care plan request asynchronously (status 202).
 * It is returned instead of CarePlanDetails when the request is accepted but not processed yet:
 * the care plan itself is available only after the job has been completed successfully.
 *
 * Keys with snake_case names, as returned by eHealth:
 * - `status`: status of the job;
 * - `eta`: expected time of the job completion;
 * - `links`: list of `entity` and `href`, where the first item points to the job to follow.
 *
 * @extends Collection<string, mixed>
 */
class CarePlanJob extends Collection
{
    /**
     * @param  iterable|null  $items
     */
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        if (is_array($this->get('links'))) {
            $this->put('links', $this->jobLinkFirst($this->get('links')));
        }
    }

    /**
     * Move the link to the job (the one with `/jobs/` in `href`) to the first place, keeping the order of the others.
     *
     * @param  array<int, array<string, mixed>>  $links
     * @return array<int, array<string, mixed>>
     */
    protected function jobLinkFirst(array $links): array
    {
        $jobLink = collect($links)->first(
            static fn (array $link): bool => str_contains(strtolower((string) ($link['href'] ?? '')), '/jobs/')
        );

        if ($jobLink === null) {
            return $links;
        }

        return [$jobLink, ...array_values(array_filter($links, static fn (array $link): bool => $link !== $jobLink))];
    }
}
