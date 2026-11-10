<?php

declare(strict_types=1);

namespace App\Dto\Phone;

use App\Contracts\Dto\Model as ModelContract;
use App\Classes\eHealth\Api\Responses\Collections\PhoneCreate;
use App\Models\Relations\Phone as PhoneModel;
use Symfony\Component\ObjectMapper\Attribute\Map;
use App\Dto\FormCollection;

#[Map(source: PhoneCreate::class)]
#[Map(source: FormCollection::class)]
class Model implements ModelContract
{
    #[Map(source: '[type]')]
    public string $type;

    #[Map(source: '[number]')]
    public string $number;

    public function toModel(): PhoneModel
    {
        return new PhoneModel($this->toArray());
    }

    /** @return array{type: string, number: string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'number' => $this->number
        ];
    }
}
