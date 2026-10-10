<?php

declare(strict_types=1);

namespace App\Dto\PaperReferral;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Paper-referral API fields; a validated caller decides whether a referral exists. */
#[Map(source: FormCollection::class)]
final class Ehealth
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[paperReferralRequisition?]', transform: [self::class, 'blank'])]
    public mixed $requisition;

    #[Map(source: '[paperReferralRequesterEmployeeName?]', transform: [self::class, 'blank'])]
    public mixed $requesterEmployeeName;

    #[Map(source: '[paperReferralRequesterLegalEntityEdrpou?]', transform: [self::class, 'blank'])]
    public mixed $requesterLegalEntityEdrpou;

    #[Map(source: '[paperReferralRequesterLegalEntityName?]', transform: [self::class, 'blank'])]
    public mixed $requesterLegalEntityName;

    #[Map(source: '[paperReferralServiceRequestDate]', transform: 'convertToYmd')]
    public string $serviceRequestDate;

    #[Map(source: '[paperReferralNote?]', transform: [self::class, 'blank'])]
    public mixed $note;

    public static function blank(mixed $value): mixed
    {
        return $value ?? '';
    }
}
