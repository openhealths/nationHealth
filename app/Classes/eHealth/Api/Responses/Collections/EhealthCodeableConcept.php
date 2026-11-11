<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated codeable concept, as returned by eHealth, with each coding wrapped as EhealthCoding.
 *
 * @extends Collection<string, mixed>
 */
class EhealthCodeableConcept extends Collection
{
    /**
     * @param  iterable|null  $items
     */
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        if ($this->has('coding')) {
            $this->put('coding', array_map(
                static fn (array|EhealthCoding $coding): EhealthCoding => $coding instanceof EhealthCoding
                    ? $coding
                    : new EhealthCoding($coding),
                $this->get('coding')
            ));
        }
    }
}
