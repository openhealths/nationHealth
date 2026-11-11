<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Codeable concept from the form, used as a DTO mapping source, with each coding wrapped as FormCoding.
 */
class FormCodeableConcept extends FormCollection
{
    /**
     * @param  iterable|null  $items
     */
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        if ($this->has('coding')) {
            $this->put('coding', array_map(
                static fn (array|FormCoding $coding): FormCoding => $coding instanceof FormCoding
                    ? $coding
                    : new FormCoding($coding),
                $this->get('coding')
            ));
        }
    }
}
