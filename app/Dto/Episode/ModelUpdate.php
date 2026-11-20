<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\Identifier\Model as IdentifierModel;
use App\Dto\Transform\Model\Reference;
use App\Enums\ResourceType;
use App\Livewire\Episode\Forms\EpisodeForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode form mapped to the database attributes eHealth allows to change on an existing episode.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: EpisodeForm::class)]
class ModelUpdate
{
    public string $name;

    #[Map(source: 'careManagerId', transform: new Reference(ResourceType::EMPLOYEE))]
    public IdentifierModel $careManager;
}
