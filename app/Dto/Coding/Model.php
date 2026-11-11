<?php

declare(strict_types=1);

namespace App\Dto\Coding;

use App\Classes\eHealth\Api\Responses\Collections\EhealthCoding;
use App\Dto\FormCoding;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Coding from the form or a validated eHealth response mapped to database attributes.
 */
#[Map(source: EhealthCoding::class)]
#[Map(source: FormCoding::class)]
class Model
{
    #[Map(source: '[system]')]
    public string $system;

    #[Map(source: '[code]')]
    public string $code;

    /**
     * Coding as database attributes.
     *
     * @return array{system: string, code: string}
     */
    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'code' => $this->code
        ];
    }
}
