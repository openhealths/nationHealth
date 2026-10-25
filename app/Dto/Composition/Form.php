<?php

declare(strict_types=1);

namespace App\Dto\Composition;

use App\Dto\FormCollection;
use App\Enums\Composition\CompositionRelation;
use App\Mapping\Transforms\ExtensionValue;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Editable refinement/continuation fields; the complete accepted document is not rewritten. */
#[Map(source: FormCollection::class)]
final class Form
{
    #[Map(source: '[section?][focus?][value?]', transform: [self::class, 'mapFocus'])]
    public string $sectionFocusUuid;

    #[Map(source: '[event?][0?][period?][start?]', transform: [self::class, 'mapStart'])]
    public string $eventPeriodStart;

    #[Map(source: '[event?][0?][period?][end?]', transform: [self::class, 'mapEnd'])]
    public string $eventPeriodEnd;

    #[Map(source: '[extension?]', transform: [new ExtensionValue('IS_ACCIDENT'), 'boolval'])]
    public bool $isAccident;

    #[Map(source: '[extension?]', transform: [new ExtensionValue('IS_INTOXICATED'), 'boolval'])]
    public bool $isIntoxicated;

    #[Map(source: '[extension?]', transform: [new ExtensionValue('IS_FOREIGN_TREATMENT'), 'boolval'])]
    public bool $isForeignTreatment;

    #[Map(source: '[extension?]', transform: [new ExtensionValue('IS_FORCE_RENEW'), 'boolval'])]
    public bool $isForceRenew;

    #[Map(source: '[extension?]', transform: [new ExtensionValue('TREATMENT_VIOLATION'), [self::class, 'mapViolation']])]
    public ?string $treatmentViolation;

    #[Map(source: '[extension?]', transform: [new ExtensionValue('TREATMENT_VIOLATION_DATE'), [self::class, 'mapViolationDate']])]
    public ?string $treatmentViolationDate;

    #[Map(source: '[identifier?][value?]')]
    public ?string $relatesToTargetUuid;

    #[Map(if: false)]
    public readonly string $relatesToCode;

    public function __construct(
        #[Map(if: false)] private readonly string $subjectUuid,
        string $sectionFocusUuid,
        #[Map(if: false)] private readonly CompositionRelation $mode = CompositionRelation::REPLACES,
    ) {
        $this->sectionFocusUuid = $sectionFocusUuid;
        $this->relatesToCode = $mode->value;
    }

    public static function fromSource(FormCollection $source, string $subjectUuid, string $sectionFocusUuid, CompositionRelation $mode = CompositionRelation::REPLACES): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
            ->map($source, new self($subjectUuid, $sectionFocusUuid, $mode));
    }

    public static function mapFocus(mixed $value, FormCollection $source, self $target): string
    {
        if (!is_string($value) || $value === '') {
            return $target->sectionFocusUuid;
        }

        return $value === data_get($source, 'subject.value') && $target->subjectUuid !== '' ? $target->subjectUuid : $value;
    }

    public static function mapStart(mixed $value, FormCollection $source, self $target): string
    {
        if ($target->mode === CompositionRelation::REPLACES) {
            return self::mapDate($value);
        }
        $end = self::mapDate(data_get($source, 'event.0.period.end'));

        return $end !== '' ? CarbonImmutable::parse($end)->addDay()->format(config('app.date_format')) : '';
    }

    public static function mapEnd(mixed $value, FormCollection $source, self $target): string
    {
        return $target->mode === CompositionRelation::APPENDS ? '' : self::mapDate($value);
    }

    public static function mapViolation(mixed $value, FormCollection $source, self $target): mixed
    {
        return $target->mode === CompositionRelation::APPENDS ? null : $value;
    }

    public static function mapViolationDate(mixed $value, FormCollection $source, self $target): ?string
    {
        return $target->mode === CompositionRelation::APPENDS ? null : (self::mapDate($value) ?: null);
    }

    private static function mapDate(mixed $value): string
    {
        return $value ? convertToAppDateFormat((string) $value) : '';
    }

    public function toArray(): array
    {
        $fields = get_object_vars($this);
        unset($fields['subjectUuid'], $fields['mode']);

        return $fields;
    }
}
