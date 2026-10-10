<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Models\CarePlan;
use App\Models\Relations\Party;
use Carbon\CarbonInterface;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Editable screen hydration from an already loaded model; credentials are reset on opening. */
#[Map(source: CarePlan::class)]
final class Form
{
    #[Map(source: '[person?][full_name?]', transform: [self::class, 'text'])]
    public string $patient;

    #[Map(source: '[encounterId?]', transform: [self::class, 'text'])]
    public string $medical_number;

    #[Map(source: '[author?][party?]', transform: [self::class, 'authorName'])]
    public string $author;

    #[Map(if: false)]
    public array $coAuthors = [];

    #[Map(source: '[category?]', transform: [self::class, 'categoryCode'])]
    public string $category;

    #[Map(source: '[context?]', transform: [self::class, 'text'])]
    public string $context;

    #[Map(source: '[title?]', transform: [self::class, 'text'])]
    public string $title;

    #[Map(if: false)]
    public string $intent = 'order';

    #[Map(source: '[periodStart?]', transform: [self::class, 'date'])]
    public string $periodStart;

    #[Map(source: '[periodStart?]', transform: [self::class, 'time'])]
    public string $periodStartTime;

    #[Map(source: '[periodEnd?]', transform: [self::class, 'date'])]
    public string $periodEnd;

    #[Map(source: '[periodEnd?]', transform: [self::class, 'time'])]
    public string $periodEndTime;

    #[Map(source: '[encounter?][uuid?]', transform: [self::class, 'text'])]
    public string $encounter;

    #[Map(source: '[description?]', transform: [self::class, 'text'])]
    public string $description;

    #[Map(source: '[note?]', transform: [self::class, 'text'])]
    public string $note;

    #[Map(source: '[informWith?]', transform: [self::class, 'text'])]
    public string $informWith;

    #[Map(source: '[supportingInfo?][episodes?]', transform: [self::class, 'items'])]
    public array $episodes;

    #[Map(source: '[supportingInfo?][medical_records?]', transform: [self::class, 'items'])]
    public array $medicalRecords;

    #[Map(if: false)]
    public string $knedp = '';
    #[Map(if: false)]
    public mixed $keyContainerUpload = null;
    #[Map(if: false)]
    public string $keyContainerFileName = '';
    #[Map(if: false)]
    public string $password = '';

    public static function text(mixed $value): string
    {
        return (string) ($value ?? '');
    }

    public static function categoryCode(mixed $value): string
    {
        return is_array($value) ? ($value['coding'][0]['code'] ?? '') : ($value ?? '');
    }

    public static function authorName(?Party $value): string
    {
        return $value?->full_name ?? '';
    }

    public static function date(?CarbonInterface $value): string
    {
        return $value?->format('d.m.Y') ?? '';
    }

    public static function time(?CarbonInterface $value): string
    {
        return $value?->format('H:i') ?? '';
    }

    public static function items(?array $value): array
    {
        return $value ?? [];
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
