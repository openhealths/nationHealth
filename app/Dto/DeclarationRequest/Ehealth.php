<?php

declare(strict_types=1);

namespace App\Dto\DeclarationRequest;

use App\Dto\EhealthMapping;
use App\Livewire\Declaration\Forms\DeclarationForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Declaration request body in the format expected
 * by the eHealth API.
 */
#[Map(source: DeclarationForm::class)]
#[Map(source: Model::class)]
class Ehealth
{
    use EhealthMapping;

    public string $personId = '';

    public string $employeeId = '';

    public string $divisionId = '';

    public ?string $authorizeWith = null;

    public ?string $parentDeclarationId = null;
}