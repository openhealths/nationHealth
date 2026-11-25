<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\Coding\Ehealth as CodingEhealth;
use App\Dto\EhealthMapping;
use App\Dto\Identifier\Ehealth as IdentifierEhealth;
use App\Dto\Transform\Ehealth\Reference;
use App\Dto\Transform\LocalDateTime;
use App\Enums\Episode\Status;
use App\Enums\ResourceType;
use App\Livewire\Episode\Forms\EpisodeForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode create payload in the format expected by the eHealth API.
 * Properties without #[Map] are taken from the form property with the same name.
 * The form has no status, so the episode is created as active.
 */
#[Map(source: EpisodeForm::class)]
class Ehealth
{
    use EhealthMapping;

    #[Map(source: 'uuid')]
    public string $id;

    #[Map(source: 'typeCode', transform: [self::class, 'type'])]
    public CodingEhealth $type;

    public string $name;

    public string $status = Status::ACTIVE->value;

    /** @var array{identifier: IdentifierEhealth} */
    #[Map(source: 'managingOrganizationId', transform: new Reference(ResourceType::LEGAL_ENTITY))]
    public array $managingOrganization;

    /** @var array{start: string} */
    #[Map(source: 'startDate', transform: [self::class, 'period'])]
    public array $period;

    /** @var array{identifier: IdentifierEhealth} */
    #[Map(source: 'careManagerId', transform: new Reference(ResourceType::EMPLOYEE))]
    public array $careManager;

    /**
     * Episode type code from the form as a coding of the episode types dictionary.
     *
     * @param  string  $typeCode
     * @return CodingEhealth
     */
    public static function type(string $typeCode): CodingEhealth
    {
        return CodingEhealth::map(['system' => 'eHealth/episode_types', 'code' => $typeCode]);
    }

    /**
     * Start date and time from the form as the period start, an ISO 8601 date-time in UTC.
     * The time is read from the form, since a transform receives the source along with the mapped value.
     *
     * @param  string  $startDate
     * @param  EpisodeForm  $form
     * @return array{start: string}
     */
    public static function period(string $startDate, EpisodeForm $form): array
    {
        return ['start' => LocalDateTime::toUtc($startDate, $form->startTime)];
    }
}
