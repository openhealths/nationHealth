<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Models\MedicalEvents\Sql\Episode;
use Illuminate\Support\Str;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Episode from the database mapped to form values.
 * Read by index, since a property path would call the relation methods of the model.
 * Properties are named after the form fields, so the form is filled with the DTO itself.
 */
#[Map(source: Episode::class)]
class Form
{
    #[Map(source: '[id]')]
    public int $episodeId;

    #[Map(source: '[uuid]')]
    public string $uuid;

    #[Map(source: '[name]')]
    public string $name;

    #[Map(source: '[type][code]')]
    public string $typeCode;

    #[Map(source: '[managingOrganization][value]')]
    public string $managingOrganizationId;

    #[Map(source: '[careManager][value]')]
    public string $careManagerId;

    #[Map(source: '[period][start]', transform: [self::class, 'startDate'])]
    public string $startDate;

    #[Map(source: '[period][start]', transform: [self::class, 'startTime'])]
    public string $startTime;

    /**
     * Date part of the stored period start, which the model reads as "d.m.Y H:i" in the app timezone.
     *
     * @param  string  $periodStart
     * @return string
     */
    public static function startDate(string $periodStart): string
    {
        return Str::before($periodStart, ' ');
    }

    /**
     * Time part of the stored period start, which the model reads as "d.m.Y H:i" in the app timezone.
     *
     * @param  string  $periodStart
     * @return string
     */
    public static function startTime(string $periodStart): string
    {
        return Str::after($periodStart, ' ');
    }
}
