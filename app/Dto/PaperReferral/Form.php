<?php

declare(strict_types=1);

namespace App\Dto\PaperReferral;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Parent clinical document to the shared editable referral fields. */
#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[paperReferral?]', transform: [self::class, 'available'])]
    public bool $isReferralAvailable;

    #[Map(source: '[paperReferral?]', transform: [self::class, 'type'])]
    public string $referralType;

    #[Map(source: '[paperReferral?][requisition?]', if: new SourceHasPath('paperReferral.requisition'))]
    public mixed $paperReferralRequisition = '';

    #[Map(source: '[paperReferral?][requesterEmployeeName?]', if: new SourceHasPath('paperReferral.requesterEmployeeName'))]
    public mixed $paperReferralRequesterEmployeeName = '';

    #[Map(source: '[paperReferral?][requesterLegalEntityEdrpou?]', if: new SourceHasPath('paperReferral.requesterLegalEntityEdrpou'))]
    public mixed $paperReferralRequesterLegalEntityEdrpou = '';

    #[Map(source: '[paperReferral?][requesterLegalEntityName?]', if: new SourceHasPath('paperReferral.requesterLegalEntityName'))]
    public mixed $paperReferralRequesterLegalEntityName = '';

    #[Map(source: '[paperReferral?][serviceRequestDate?]', transform: 'convertToAppDateFormat')]
    public string $paperReferralServiceRequestDate;

    #[Map(source: '[paperReferral?][note?]', if: new SourceHasPath('paperReferral.note'))]
    public mixed $paperReferralNote = '';

    public static function available(mixed $paper, Collection $source): bool
    {
        return !empty($paper) || !empty($source['basedOn']);
    }

    public static function type(mixed $paper, Collection $source): string
    {
        return !empty($paper) ? 'paper' : (!empty($source['basedOn']) ? 'electronic' : '');
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
