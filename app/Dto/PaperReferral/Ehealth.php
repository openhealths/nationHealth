<?php

declare(strict_types=1);

namespace App\Dto\PaperReferral;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** Nested resource fields; the parent API retains its existing wire-name conversion. */
#[Map(source: FormCollection::class)]
final class Ehealth
{
    #[Map(source: '[paperReferralRequisition?]', transform: [self::class, 'blank'])]
    public mixed $requisition;

    #[Map(source: '[paperReferralRequesterEmployeeName?]', transform: [self::class, 'blank'])]
    public mixed $requesterEmployeeName;

    #[Map(source: '[paperReferralRequesterLegalEntityEdrpou]', transform: [self::class, 'blank'])]
    public mixed $requesterLegalEntityEdrpou;

    #[Map(source: '[paperReferralRequesterLegalEntityName?]', transform: [self::class, 'blank'])]
    public mixed $requesterLegalEntityName;

    #[Map(source: '[paperReferralServiceRequestDate]', transform: 'convertToYmd')]
    public string $serviceRequestDate;

    #[Map(source: '[paperReferralNote?]', transform: [self::class, 'blank'])]
    public mixed $note;

    public static function fromSource(FormCollection $source): ?self
    {
        if (empty($source['paperReferralRequesterLegalEntityEdrpou'])) {
            return null;
        }

        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($source, self::class);
    }

    public static function blank(mixed $value): mixed
    {
        return $value ?? '';
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
