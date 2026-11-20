<?php

declare(strict_types=1);

namespace App\Dto\Coding;

use App\Models\MedicalEvents\Sql\Coding as SqlCoding;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Coding from the database mapped to form values.
 * Read by index, as the codeable concept is.
 */
#[Map(source: SqlCoding::class)]
class Form
{
    #[Map(source: '[system]')]
    public string $system;

    #[Map(source: '[code]')]
    public string $code;

    /**
     * Coding as form values.
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
