<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Dto\Coding\Model as CodingModel;
use App\Dto\Identifier\Model as IdentifierModel;
use App\Dto\Transform\LocalDateTime;
use App\Dto\Transform\Model\Reference;
use App\Enums\Episode\Status;
use App\Enums\ResourceType;
use App\Livewire\Episode\Forms\EpisodeForm;
use App\Models\MedicalEvents\Sql\Episode;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Episode form mapped to database attributes, which are then mapped to the episode model.
 * Every property stored on the episode has its own #[Map], since Eloquent attributes are not declared properties
 * and the mapper copies a property without #[Map] only to a declared property of the same name.
 * The type, the identifiers and the period start are mapped from the form only, since they are stored as related records.
 * The form has no status, so the episode keeps the draft status.
 */
#[Map(target: Episode::class, source: EpisodeForm::class)]
class Model
{
    #[Map]
    public string $uuid;

    #[Map]
    public string $name;

    #[Map(if: new TargetClass(Episode::class))]
    public string $status = Status::DRAFT->value;

    #[Map(source: 'typeCode', if: new SourceClass(EpisodeForm::class), transform: [self::class, 'type'])]
    public CodingModel $type;

    #[Map(source: 'managingOrganizationId', if: new SourceClass(EpisodeForm::class), transform: new Reference(ResourceType::LEGAL_ENTITY))]
    public IdentifierModel $managingOrganization;

    #[Map(source: 'careManagerId', if: new SourceClass(EpisodeForm::class), transform: new Reference(ResourceType::EMPLOYEE))]
    public IdentifierModel $careManager;

    #[Map(source: 'startDate', if: new SourceClass(EpisodeForm::class), transform: [self::class, 'periodStart'])]
    public string $periodStart;

    /**
     * Episode type code from the form as a coding of the episode types dictionary.
     *
     * @param  string  $typeCode
     * @return CodingModel
     */
    public static function type(string $typeCode): CodingModel
    {
        return CodingModel::map(['system' => 'eHealth/episode_types', 'code' => $typeCode]);
    }

    /**
     * Start date and time from the form as an ISO 8601 date-time in UTC.
     * The time is read from the form, since a transform receives the source along with the mapped value.
     *
     * @param  string  $startDate
     * @param  EpisodeForm  $form
     * @return string
     */
    public static function periodStart(string $startDate, EpisodeForm $form): string
    {
        return LocalDateTime::toUtc($startDate, $form->startTime);
    }
}
