<?php

declare(strict_types=1);

namespace App\Dto\Input;

use Illuminate\Support\Collection;

/**
 * Nested form data used as a DTO mapping source.
 * As an example, this is used for mapping nested phone and address data from the DivisionForm to the Division Model.
 * It can be used for mapping any nested form data to a model.
 *
 * @extends Collection<string, mixed>
 */
class FormCollection extends Collection
{
}
