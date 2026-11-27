<?php

declare(strict_types=1);

namespace App\Dto\DeclarationRequest;

use App\Livewire\Declaration\Forms\DeclarationForm;
use App\Models\DeclarationRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Declaration request model mapped to Livewire form data.
 */
#[Map(source: DeclarationRequest::class, target: DeclarationForm::class)]
class Form
{
    #[Map(source: 'attributes', if: new SourceClass(DeclarationRequest::class), transform: [self::class, 'employeeUuid'])]
    #[Map(target: 'employeeId', if: new TargetClass(DeclarationForm::class))]
    public string $employeeId = '';

    public ?string $authorizeWith = null;

    public static function employeeUuid(array $attributes, DeclarationRequest $source): string
    {
        return $source->getRelation('employee')?->uuid ?? '';
    }
}