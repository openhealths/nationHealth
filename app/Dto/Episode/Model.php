<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Contracts\Dto\Model as ModelContract;
use App\Core\Arr;
use App\Dto\Coding\Model as CodingModel;
use App\Dto\Identifier\Model as IdentifierModel;
use App\Dto\Transform\Model\Reference;
use App\Enums\Episode\Status;
use App\Enums\ResourceType;
use App\Livewire\Episode\Forms\EpisodeForm;
use App\Models\MedicalEvents\Sql\Episode;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode form mapped to database attributes.
 * Properties without #[Map] are taken from the form property with the same name.
 * The form has no status, so the episode keeps the draft status.
 */
#[Map(source: EpisodeForm::class)]
class Model implements ModelContract
{
    public string $uuid;

    public string $name;

    public string $status = Status::DRAFT->value;

    #[Map(source: 'typeCode', transform: [self::class, 'type'])]
    public CodingModel $type;

    #[Map(source: 'managingOrganizationId', transform: new Reference(ResourceType::LEGAL_ENTITY))]
    public IdentifierModel $managingOrganization;

    #[Map(source: 'careManagerId', transform: new Reference(ResourceType::EMPLOYEE))]
    public IdentifierModel $careManager;

    #[Map(source: 'startDate', transform: [self::class, 'periodStart'])]
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
        return convertToEHealthISO8601("$startDate $form->startTime");
    }

    /**
     * Episode attributes without the type, the identifiers and the period start, which are stored as related records.
     *
     * @param  Episode|null  $episode
     * @return Episode
     */
    public function toModel(?Episode $episode = null): Episode
    {
        $episode ??= new Episode();
        $relatedRecordProperties = ['type', 'managingOrganization', 'careManager', 'periodStart'];

        foreach (Arr::except(get_object_vars($this), $relatedRecordProperties) as $key => $value) {
            $episode->setAttribute($key, $value);
        }

        return $episode;
    }
}
