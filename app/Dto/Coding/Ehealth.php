<?php

declare(strict_types=1);

namespace App\Dto\Coding;

use App\Dto\FormCoding;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Coding from the form in the format expected by the eHealth API.
 */
#[Map(source: FormCoding::class)]
class Ehealth
{
    #[Map(source: '[system]')]
    public string $system;

    #[Map(source: '[code]')]
    public string $code;
}
