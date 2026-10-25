<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\Fhir\ResourceData;
use App\Dto\FormCollection;
use App\Mapping\Conditions\SourceHasPath;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** UI projection; retain the complete resource separately as the editing seed. */
#[Map(source: FormCollection::class)]
final class Form implements Arrayable
{
    use ResourceData;
    #[Map(source: '[title?]', if: new SourceHasPath('title'))]
    public mixed $title;

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description;

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note;

    #[Map(source: '[intent?]', if: new SourceHasPath('intent'))]
    public mixed $intent;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status;

    #[Map(source: '[requisition?]', if: new SourceHasPath('requisition'))]
    public mixed $requisition;

    #[Map(source: '[addresses?]', if: new SourceHasPath('addresses'))]
    public mixed $addresses;

    #[Map(source: '[supportingInfo?]', if: new SourceHasPath('supportingInfo'))]
    public mixed $supportingInfo;

    #[Map(source: '[basedOn?]', if: new SourceHasPath('basedOn'))]
    public mixed $basedOn;

    #[Map(source: '[partOf?]', if: new SourceHasPath('partOf'))]
    public mixed $partOf;

    #[Map(source: '[context?]', if: new SourceHasPath('context'))]
    public mixed $context;

    #[Map(source: '[category?]', transform: [self::class, 'mapCategory'])]
    public mixed $category;

    #[Map(source: '[termsOfService?]', transform: [self::class, 'mapTermsOfService'])]
    public mixed $termsOfService;

    #[Map(source: '[author?]', transform: [self::class, 'mapAuthor'])]
    public mixed $author;

    #[Map(source: '[encounter?]', transform: [self::class, 'mapEncounter'])]
    public mixed $encounter;

    #[Map(source: '[careManager?]', transform: [self::class, 'mapCareManager'])]
    public mixed $careManager;

    #[Map(source: '[managingOrganization?]', transform: [self::class, 'mapManagingOrganization'])]
    public mixed $managingOrganization;

    #[Map(source: '[id]')]
    public mixed $uuid;

    #[Map(source: '[contributor?]', transform: [self::class, 'mapCoAuthors'])]
    public array $coAuthors;

    #[Map(source: '[supportingInfo?]', transform: [self::class, 'mapEpisodes'])]
    public array $episodes;

    #[Map(source: '[supportingInfo?]', transform: [self::class, 'mapMedicalRecords'])]
    public array $medicalRecords;

    #[Map(source: '[period?][start?]', transform: [self::class, 'mapPeriodStart'])]
    public string $periodStart;

    #[Map(source: '[period?][start?]', transform: [self::class, 'mapPeriodStartTime'])]
    public string $periodStartTime;

    #[Map(source: '[period?][end?]', transform: [self::class, 'mapPeriodEnd'])]
    public string $periodEnd;

    #[Map(source: '[period?][end?]', transform: [self::class, 'mapPeriodEndTime'])]
    public string $periodEndTime;

    #[Map(source: '[informWith?]', if: new SourceHasPath('informWith'), transform: [self::class, 'mapInformWith'])]
    public mixed $informWith;
    public function __construct(
        #[Map(if: false)] private readonly array $options = [],
        #[Map(if: false)] private readonly array $input = [],
    ) {
    }

    public static function fromSource(FormCollection $source, array $context = []): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($source, new self($context, $source->all()));
    }

    public static function mapCategory(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'category.coding.0.code', '');
    }

    public static function mapTermsOfService(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'termsOfService.coding.0.code', '');
    }

    public static function mapAuthor(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'author.identifier.value', '');
    }

    public static function mapEncounter(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'encounter.identifier.value', '');
    }

    public static function mapCareManager(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'careManager.identifier.value', '');
    }

    public static function mapManagingOrganization(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'managingOrganization.identifier.value', '');
    }

    public static function mapCoAuthors(mixed $value): array
    {
        return array_map(static fn (array $reference): string => $reference['identifier']['value'], $value ?? []);
    }

    public static function mapEpisodes(mixed $value): array
    {
        return array_map(static fn (array $reference): array => ['uuid' => $reference['identifier']['value']], array_values(array_filter($value ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care')));
    }

    public static function mapMedicalRecords(mixed $value): array
    {
        return array_values(array_filter($value ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') !== 'episode_of_care'));
    }

    public static function mapPeriodStart(mixed $value, FormCollection $source, self $target): string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone($target->options['timezone'] ?? 'UTC')->format(($target->options['dateFormat'] ?? 'Y-m-d')) : '';
    }

    public static function mapPeriodStartTime(mixed $value, FormCollection $source, self $target): string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone($target->options['timezone'] ?? 'UTC')->format('H:i:s') : '';
    }

    public static function mapPeriodEnd(mixed $value, FormCollection $source, self $target): string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone($target->options['timezone'] ?? 'UTC')->format(($target->options['dateFormat'] ?? 'Y-m-d')) : '';
    }

    public static function mapPeriodEndTime(mixed $value, FormCollection $source, self $target): string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone($target->options['timezone'] ?? 'UTC')->format('H:i:s') : '';
    }

    public static function mapInformWith(mixed $value, FormCollection $source): mixed
    {
        return data_get($source, 'informWith.authMethodId', '');
    }

    public function toArray(): array
    {
        $fields = $this->mappedFields();
        $copy = ['title', 'description', 'note', 'intent', 'status', 'requisition', 'addresses', 'supportingInfo', 'basedOn', 'partOf', 'context'];
        $order = [...array_intersect(array_keys($this->input), $copy), 'uuid', 'category', 'termsOfService', 'author', 'encounter', 'careManager', 'managingOrganization', 'coAuthors', 'episodes', 'medicalRecords', 'periodStart', 'periodStartTime', 'periodEnd', 'periodEndTime', 'informWith'];
        $result = [];
        foreach ($order as $field) {
            if (array_key_exists($field, $fields)) {
                $result[$field] = $fields[$field];
            }
        }

        return $result;
    }
}
