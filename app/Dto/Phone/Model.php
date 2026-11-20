<?php

declare(strict_types=1);

namespace App\Dto\Phone;

use App\Classes\eHealth\Api\Responses\Collections\PhoneCreate;
use App\Contracts\Dto\Model as ModelContract;
use App\Dto\Input\FormCollection;
use App\Models\Relations\Phone as PhoneModel;
use Symfony\Component\ObjectMapper\Attribute\Map;

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
        return new PhoneModel(get_object_vars($this));
    }
}
