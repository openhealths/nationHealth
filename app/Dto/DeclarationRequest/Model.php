<?php

declare(strict_types=1);

namespace App\Dto\DeclarationRequest;

use App\Enums\Declaration\RequestStatus;
use App\Livewire\Declaration\Forms\DeclarationForm;
use App\Models\DeclarationRequest;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use App\Models\ReorganizationEmployeeDeclaration;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/**
 * Maps declaration request form data to model attributes.
 */
#[Map(source: DeclarationForm::class)]
#[Map(target: DeclarationRequest::class)]
class Model
{
    #[Map(target: 'personId', if: false)]
    public string $personId = '';

    #[Map(target: 'employeeId', if: false)]
    public string $employeeId = '';

    #[Map(target: 'divisionId', if: false)]
    public string $divisionId = '';

    #[Map(target: '[authorizeWith]', if: new SourceClass(self::class))]
    public ?string $authorizeWith = null;

    #[Map(target: '[parentDeclarationUuid]', if: new SourceClass(self::class))]
    public ?string $parentDeclarationId = null;

    #[Map(target: '[status]', if: new SourceClass(self::class))]
    public string $status = RequestStatus::DRAFT->value;

    /**
     * Prepare a declaration request for a reorganized legal entity.
     */
    public static function fromReorganization(ReorganizationEmployeeDeclaration $declaration, Person $person, Employee $employee): self {
        $data = new self();

        $data->personId = $person->uuid;
        $data->employeeId = $employee->uuid;
        $data->divisionId = $employee->division->uuid;
        $data->authorizeWith = $declaration->authorizeWith;
        $data->parentDeclarationId = $declaration->declarationUuid;

        return $data;
    }
}