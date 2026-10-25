<?php

declare(strict_types=1);

namespace App\Dto\Composition;

use App\Enums\Composition\CompositionType;
use App\Livewire\Composition\Forms\CompositionCancellationForm;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[Map(source: CompositionCancellationForm::class)]
final class EhealthCancellation
{
    #[Map(source: 'reason', transform: [self::class, 'mapReason'])]
    public array $reason;

    public function __construct(
        #[Map(if: false)] public readonly string $id,
        #[Map(if: false)] private readonly CompositionType $compositionType,
    ) {
    }

    public static function fromForm(CompositionCancellationForm $form, string $id, CompositionType $type): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
            ->map($form, new self($id, $type));
    }

    public static function mapReason(string $value, CompositionCancellationForm $source, self $target): array
    {
        return [
            'coding' => [['system' => 'eHealth/'.$target->compositionType->cancellationReasonDictionary(), 'code' => $value]],
            'text' => $source->reasonText,
        ];
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'reason' => $this->reason];
    }
}
