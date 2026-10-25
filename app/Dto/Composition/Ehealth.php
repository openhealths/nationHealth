<?php

declare(strict_types=1);

namespace App\Dto\Composition;

use App\Dto\FormCollection;
use App\Enums\Composition\CompositionRelation;
use App\Enums\Composition\CompositionType;
use App\Livewire\Composition\Forms\CompositionForm;
use App\Livewire\Composition\Forms\CompositionTempDisabilityForm;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** The existing IL compositionRequest contract uses camelCase and bare Identifiers. */
#[Map(source: CompositionForm::class)]
#[Map(source: CompositionTempDisabilityForm::class)]
final class Ehealth
{
    #[Map(source: 'type', transform: [self::class, 'compositionType'])]
    public array $type;

    #[Map(source: 'category', transform: [self::class, 'mapCategory'])]
    public array $category;

    /** @var list<Event> */
    #[Map(source: 'type', transform: [[self::class, 'events'], new MapCollection(targetClass: Event::class)])]
    public array $event;

    #[Map(source: 'prepersonUuid', if: new SourceClass(CompositionForm::class), transform: [self::class, 'newbornSubject'])]
    #[Map(source: 'subjectUuid', if: new SourceClass(CompositionTempDisabilityForm::class), transform: [self::class, 'disabilitySubject'])]
    public array $subject;

    #[Map(source: 'encounterUuid', transform: [self::class, 'mapEncounter'])]
    public array $encounter;

    #[Map(if: false)]
    public readonly array $author;

    #[Map(source: 'personUuid', if: new SourceClass(CompositionForm::class), transform: [self::class, 'newbornFocus'])]
    #[Map(source: 'sectionFocusUuid', if: new SourceClass(CompositionTempDisabilityForm::class), transform: [self::class, 'disabilityFocus'])]
    public array $section;

    /** @var list<Extension> */
    #[Map(source: 'informWithUuid', transform: [[self::class, 'extensions'], new MapCollection(targetClass: Extension::class)])]
    public array $extension;

    #[Map(source: 'relatesToTargetUuid', if: new SourceClass(CompositionTempDisabilityForm::class), transform: [self::class, 'relation'])]
    public ?array $relatesTo = null;

    public function __construct(string $authorEmployeeUuid)
    {
        $this->author = self::identifier('employee', $authorEmployeeUuid);
    }

    public static function fromForm(CompositionForm|CompositionTempDisabilityForm $form, string $authorEmployeeUuid): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
            ->map($form, new self($authorEmployeeUuid));
    }

    public static function compositionType(string $value, CompositionForm|CompositionTempDisabilityForm $source): array
    {
        return self::concept('COMPOSITION_TYPES', $source instanceof CompositionForm ? CompositionType::NEWBORN->value : CompositionType::TEMP_DISABILITY->value);
    }

    public static function mapCategory(string $value): array
    {
        return self::concept('COMPOSITION_CATEGORIES', $value);
    }

    /** @return list<FormCollection> */
    public static function events(string $value, CompositionForm|CompositionTempDisabilityForm $source): array
    {
        $newborn = $source instanceof CompositionForm;
        $start = $newborn ? $source->newbornBirthDate : $source->eventPeriodStart;

        return [new FormCollection(['period' => [
            'start' => self::date($start).'T00:00:01Z',
            'end' => $newborn ? null : self::date($source->eventPeriodEnd).'T20:59:59Z',
        ]])];
    }

    public static function newbornSubject(string $value): array
    {
        return self::identifier('preperson', $value);
    }

    public static function disabilitySubject(string $value, CompositionTempDisabilityForm $source): array
    {
        return self::identifier($source->isUnidentified ? 'preperson' : 'person', $value);
    }

    public static function mapEncounter(string $value): array
    {
        return self::identifier('encounter', $value);
    }

    public static function newbornFocus(string $value): array
    {
        return ['focus' => self::identifier('person', $value)];
    }

    public static function disabilityFocus(string $value, CompositionTempDisabilityForm $source): array
    {
        return ['focus' => self::disabilitySubject($value, $source)];
    }

    /** @return list<FormCollection> */
    public static function extensions(?string $value, CompositionForm|CompositionTempDisabilityForm $source): array
    {
        $rows = $value ? [['valueCode' => 'INFORM_WITH', 'valueUuid' => $value]] : [];
        if ($source instanceof CompositionForm) {
            $rows[] = ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => self::date($source->newbornBirthDate)];
            $rows[] = ['valueCode' => 'NEWBORN_SEX', 'valueString' => $source->newbornSex];
        } else {
            foreach (['IS_ACCIDENT' => $source->isAccident, 'IS_INTOXICATED' => $source->isIntoxicated, 'IS_FOREIGN_TREATMENT' => $source->isForeignTreatment, 'IS_FORCE_RENEW' => $source->isForceRenew] as $code => $enabled) {
                if ($enabled) {
                    $rows[] = ['valueCode' => $code, 'valueBoolean' => true];
                }
            }
            if (!empty($source->treatmentViolation)) {
                $rows[] = ['valueCode' => 'TREATMENT_VIOLATION', 'valueString' => $source->treatmentViolation];
                if (!empty($source->treatmentViolationDate)) {
                    $rows[] = ['valueCode' => 'TREATMENT_VIOLATION_DATE', 'valueDate' => self::date($source->treatmentViolationDate)];
                }
            }
        }

        return array_map(static fn (array $row): FormCollection => new FormCollection($row), $rows);
    }

    public static function relation(?string $value, CompositionTempDisabilityForm $source): ?array
    {
        return empty($value) ? null : [
            'code' => $source->relatesToCode ?? CompositionRelation::REPLACES->value,
            'targetIdentifier' => self::identifier('composition', $value),
        ];
    }

    private static function concept(string $system, string $code): array
    {
        return ['coding' => [['system' => $system, 'code' => $code]]];
    }

    private static function identifier(string $resource, string $uuid): array
    {
        return ['type' => [...self::concept('eHealth/resources', $resource), 'text' => $resource], 'value' => $uuid];
    }

    private static function date(string $value): string
    {
        return CarbonImmutable::parse($value)->format('Y-m-d');
    }

    /** Preserve IL casing, ordering, explicit null period end and empty extension list. */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'category' => $this->category,
            'event' => array_map(static fn (Event $event): array => $event->toArray(), $this->event),
            'subject' => $this->subject,
            'encounter' => $this->encounter,
            'author' => $this->author,
            'section' => $this->section,
            'extension' => array_map(static fn (Extension $extension): array => $extension->toArray(), $this->extension),
        ];
        if ($this->relatesTo !== null) {
            $data['relatesTo'] = $this->relatesTo;
        }

        return $data;
    }
}
