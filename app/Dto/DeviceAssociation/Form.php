<?php

declare(strict_types=1);

namespace App\Dto\DeviceAssociation;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Already-loaded repository document to an editable encounter association. */
#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]')]
    public mixed $uuid;

    #[Map(source: '[device?][identifier?][value?]', if: new SourceHasPath('device.identifier.value'))]
    public mixed $deviceId = '';

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = '';

    #[Map(source: '[associationDate?]', transform: 'convertToAppDateFormat')]
    public string $associationDate;

    #[Map(source: '[bodySite?][coding?][0?][code?]', if: new SourceHasPath('bodySite.coding.0.code'))]
    public mixed $bodySiteCode = '';

    #[Map(source: '[bodySite?][text?]', if: new SourceHasPath('bodySite.text'))]
    public mixed $bodySiteText = '';

    #[Map(source: '[recorded?]', if: new SourceHasPath('recorded'))]
    public mixed $recorded = '';

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[reportOrigin?][text?]', if: new SourceHasPath('reportOrigin.text'))]
    public mixed $reportOriginText = '';

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
