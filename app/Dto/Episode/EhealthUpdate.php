<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\EhealthMapping;
use App\Dto\Identifier\Ehealth as IdentifierEhealth;
use App\Dto\Transform\Ehealth\Reference;
use App\Enums\ResourceType;
use App\Livewire\Episode\Forms\EpisodeForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode update payload in the format expected by the eHealth API: only the fields eHealth allows to change.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: EpisodeForm::class)]
class EhealthUpdate
{
    use EhealthMapping;

    public string $name;

    /** @var array{identifier: IdentifierEhealth} */
    #[Map(source: 'careManagerId', transform: new Reference(ResourceType::EMPLOYEE))]
    public array $careManager;
}
