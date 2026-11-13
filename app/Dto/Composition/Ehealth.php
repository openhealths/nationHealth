<?php

declare(strict_types=1);

namespace App\Dto\Composition;

use App\Enums\Composition\CompositionType;
use App\Livewire\Composition\Forms\CompositionForm;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Birth conclusion fields mapped from the form; IL identifiers and extension keys retain their casing. */
#[Map(source: CompositionForm::class)]
class Ehealth
{
    public string $category;

    public string $prepersonUuid;

    public string $encounterUuid;

    public string $personUuid;

    public ?string $informWithUuid = null;

    public string $newbornBirthDate;

    public string $newbornSex;

    /** The author comes from verified encounter context, never from editable form fields. */
    #[Map(if: false)]
    protected string $authorEmployeeUuid;

    public function __construct(string $authorEmployeeUuid)
    {
        $this->authorEmployeeUuid = $authorEmployeeUuid;
    }

    /** Compatibility entry point for existing form payload consumers. */
    public function fromForm(CompositionForm $form): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($form, $this);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $birthDate = CarbonImmutable::parse($this->newbornBirthDate)->format('Y-m-d');
        $extensions = $this->informWithUuid
            ? [['valueCode' => 'INFORM_WITH', 'valueUuid' => $this->informWithUuid]]
            : [];
        $extensions[] = ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => $birthDate];
        $extensions[] = ['valueCode' => 'NEWBORN_SEX', 'valueString' => $this->newbornSex];

        return [
            'type' => $this->concept('COMPOSITION_TYPES', CompositionType::NEWBORN->value),
            'category' => $this->concept('COMPOSITION_CATEGORIES', $this->category),
            'event' => [[
                'code' => $this->concept('COMPOSITION_EVENTS', 'COMPOSITION_VALIDITY_PERIOD'),
                // Birth conclusions retain the explicit null end from the existing IL payload.
                'period' => ['start' => $birthDate.'T00:00:01Z', 'end' => null],
            ]],
            'subject' => $this->identifier('preperson', $this->prepersonUuid),
            'encounter' => $this->identifier('encounter', $this->encounterUuid),
            'author' => $this->identifier('employee', $this->authorEmployeeUuid),
            'section' => ['focus' => $this->identifier('person', $this->personUuid)],
            'extension' => $extensions,
        ];
    }

    /** @return array{coding: list<array{system: string, code: string}>} */
    protected function concept(string $system, string $code): array
    {
        return ['coding' => [['system' => $system, 'code' => $code]]];
    }

    /** @return array<string, mixed> */
    protected function identifier(string $resource, string $uuid): array
    {
        return [
            'type' => [...$this->concept('eHealth/resources', $resource), 'text' => $resource],
            'value' => $uuid,
        ];
    }
}
