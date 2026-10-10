<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanSync;
use App\Enums\CarePlanStatus;
use App\Mapping\Transforms\FallbackValue;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/** Local editable fields only; ownership, author and encounter context are resolved by the caller. */
#[Map(source: CarePlanForm::class, if: new SourceClass(CarePlanForm::class))]
#[Map(source: CarePlanSync::class, if: new SourceClass(CarePlanSync::class))]
final class Model
{
    #[Map(source: '[id?]', if: new SourceClass(CarePlanSync::class), transform: new FallbackValue('uuid'))]
    public ?string $uuid = null;

    #[Map(source: '[status?]', if: new SourceClass(CarePlanSync::class), transform: [self::class, 'remoteStatus'])]
    public ?string $status = null;

    #[Map(if: new SourceClass(CarePlanForm::class))]
    public string $category;

    #[Map(if: new SourceClass(CarePlanForm::class), transform: [self::class, 'optionalString'])]
    public ?string $context = null;

    #[Map(if: new SourceClass(CarePlanForm::class))]
    #[Map(source: '[title?]', if: new SourceClass(CarePlanSync::class), transform: [self::class, 'remoteTitle'])]
    public string $title;

    #[Map(source: 'termsOfService', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'optionalString'])]
    #[Map(source: '[terms_of_service?][coding?][0?][code?]', if: new SourceClass(CarePlanSync::class))]
    public ?string $terms_of_service = null;

    #[Map(source: 'periodStart', if: new SourceClass(CarePlanForm::class), transform: 'convertToYmd')]
    #[Map(source: '[period?][start?]', if: new SourceClass(CarePlanSync::class), transform: [self::class, 'remoteStart'])]
    public mixed $period_start;

    #[Map(source: 'periodEnd', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'optionalDate'])]
    #[Map(source: '[period?][end?]', if: new SourceClass(CarePlanSync::class), transform: [self::class, 'remoteEnd'])]
    public mixed $period_end = null;

    #[Map(source: 'episodes', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'mapSupportingInfo'])]
    public array $supporting_info;

    #[Map(if: new SourceClass(CarePlanForm::class), transform: [self::class, 'optionalString'])]
    #[Map(source: '[description?]', if: new SourceClass(CarePlanSync::class), transform: [self::class, 'remoteDescription'])]
    public ?string $description = null;

    #[Map(if: new SourceClass(CarePlanForm::class), transform: [self::class, 'optionalString'])]
    #[Map(source: '[note?]', if: new SourceClass(CarePlanSync::class), transform: [self::class, 'remoteNote'])]
    public ?string $note = null;

    #[Map(source: 'informWith', if: new SourceClass(CarePlanForm::class), transform: [self::class, 'optionalString'])]
    public ?string $inform_with = null;

    public function __construct(
        #[Map(if: false)] private readonly string $fallbackTitle = 'План лікування',
        #[Map(if: false)] private readonly ?string $fallbackDescription = null,
        #[Map(if: false)] private readonly ?string $fallbackNote = null,
        #[Map(if: false)] private readonly ?CarbonInterface $mappedAt = null,
    ) {
    }

    public static function remoteStatus(?string $value): string
    {
        return $value ?? CarePlanStatus::ACTIVE->value;
    }

    public static function remoteTitle(?string $value, CarePlanSync $source, self $target): string
    {
        return !empty($value) ? $value : $target->fallbackTitle;
    }

    public static function remoteDescription(?string $value, CarePlanSync $source, self $target): ?string
    {
        return !empty($value) ? $value : $target->fallbackDescription;
    }

    public static function remoteNote(?string $value, CarePlanSync $source, self $target): ?string
    {
        return !empty($value) ? $value : $target->fallbackNote;
    }

    public static function remoteStart(mixed $value, CarePlanSync $source, self $target): mixed
    {
        return $value !== null ? Carbon::parse($value) : ($source['ehealth_inserted_at'] ?? $target->mappedAt);
    }

    public static function remoteEnd(?string $value): ?Carbon
    {
        return $value !== null ? Carbon::parse($value) : null;
    }

    public static function optionalString(string $value): ?string
    {
        return $value ?: null;
    }

    public static function optionalDate(string $value): ?string
    {
        return $value ? convertToYmd($value) : null;
    }

    public static function mapSupportingInfo(array $episodes, CarePlanForm $source): array
    {
        // These are local display snapshots; retain their keys and fields rather than emitting FHIR references.
        return ['episodes' => $episodes, 'medical_records' => $source->medicalRecords];
    }

    public function toArray(): array
    {
        return array_intersect_key(get_object_vars($this), array_flip([
            'category', 'context', 'title', 'terms_of_service', 'period_start', 'period_end',
            'supporting_info', 'description', 'note', 'inform_with',
        ]));
    }

    public function toSyncAttributes(): array
    {
        return array_intersect_key(get_object_vars($this), array_flip([
            'uuid', 'status', 'title', 'description', 'note', 'period_start', 'period_end', 'terms_of_service',
        ]));
    }
}
