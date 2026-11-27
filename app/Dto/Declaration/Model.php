<?php

declare(strict_types=1);

namespace App\Dto\Declaration;

use App\Classes\eHealth\Api\Responses\Collections\DeclarationGet;
use App\Classes\eHealth\Api\Responses\Collections\DeclarationRequestSign;
use App\Enums\Declaration\Status;
use App\Models\Declaration;
use Carbon\Carbon;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/**
 * Maps declaration responses to Eloquent model attributes.
 */
#[Map(source: DeclarationGet::class)]
#[Map(source: DeclarationRequestSign::class)]
#[Map(target: Declaration::class)]
class Model
{
    #[Map(source: '[id]', if: new SourceClass(DeclarationGet::class))]
    #[Map(source: '[id]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: '[uuid]', if: new SourceClass(self::class))]
    public string $uuid = '';

    #[Map(source: '[declaration_number?]')]
    #[Map(target: '[declarationNumber]', if: new SourceClass(self::class))]
    public ?string $declarationNumber = null;

    #[Map(source: '[declaration_request_id]', if: new SourceClass(DeclarationGet::class))]
    #[Map(source: '[declaration_request_id]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: 'declarationRequestUuid', if: false)]
    public string $declarationRequestUuid = '';

    #[Map(source: '[division?]', if: new SourceClass(DeclarationGet::class), transform: [self::class, 'entityUuid'])]
    #[Map(source: '[division_id?]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: 'divisionUuid', if: false)]
    public ?string $divisionUuid = null;

    #[Map(source: '[employee]', if: new SourceClass(DeclarationGet::class), transform: [self::class, 'entityUuid'])]
    #[Map(source: '[employee_id]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: 'employeeUuid', if: false)]
    public string $employeeUuid = '';

    #[Map(source: '[legal_entity]', if: new SourceClass(DeclarationGet::class), transform: [self::class, 'entityUuid'])]
    #[Map(source: '[legal_entity_id]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: 'legalEntityUuid', if: false)]
    public string $legalEntityUuid = '';

    #[Map(source: '[person]', if: new SourceClass(DeclarationGet::class), transform: [self::class, 'entityUuid'])]
    #[Map(source: '[person_id]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: 'personUuid', if: false)]
    public string $personUuid = '';

    #[Map(source: '[start_date]')]
    #[Map(target: '[startDate]', if: new SourceClass(self::class))]
    public string $startDate = '';

    #[Map(source: '[end_date]')]
    #[Map(target: '[endDate]', if: new SourceClass(self::class))]
    public string $endDate = '';

    #[Map(source: '[inserted_at]', transform: [self::class, 'dateTime'])]
    #[Map(target: '[insertedAt]', if: new SourceClass(self::class))]
    public ?string $insertedAt = null;

    #[Map(source: '[signed_at?]', transform: [self::class, 'dateTime'])]
    #[Map(target: '[signedAt]', if: new SourceClass(self::class))]
    public ?string $signedAt = null;

    #[Map(source: '[reason?]')]
    #[Map(target: '[reason]', if: new SourceClass(self::class))]
    public ?string $reason = null;

    #[Map(source: '[reason_description?]')]
    #[Map(target: '[reasonDescription]', if: new SourceClass(self::class))]
    public ?string $reasonDescription = null;

    #[Map(source: '[status]')]
    #[Map(target: '[status]', if: new SourceClass(self::class))]
    public string $status = '';

    #[Map(source: '[status]', if: new SourceClass(DeclarationGet::class), transform: [self::class, 'isActiveStatus'])]
    #[Map(source: '[is_active]', if: new SourceClass(DeclarationRequestSign::class))]
    #[Map(target: '[isActive]', if: new SourceClass(self::class))]
    public bool $isActive = false;

    #[Map(source: '[person?]', if: new SourceClass(DeclarationGet::class))]
    #[Map(target: 'person', if: false)]
    public ?array $person = null;

    #[Map(source: '[authentication_method_current?]', if: new SourceClass(DeclarationGet::class))]
    #[Map(target: 'authenticationMethodCurrent', if: false)]
    public ?array $authenticationMethodCurrent = null;

    /**
     * Extract an eHealth entity UUID.
     */
    public static function entityUuid(?array $entity): ?string
    {
        return $entity['id'] ?? null;
    }

    /**
     * Convert an eHealth date to database format.
     */
    public static function dateTime(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    /**
     * Determine whether the declaration status is active.
     */
    public static function isActiveStatus(string $status): bool
    {
        return $status === Status::ACTIVE->value;
    }
}