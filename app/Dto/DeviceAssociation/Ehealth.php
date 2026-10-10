<?php

declare(strict_types=1);

namespace App\Dto\DeviceAssociation;

use App\Dto\EhealthMapping;
use App\Dto\FormCollection;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** The encounter caller dates new associations before mapping; no clock or UUID generation here. */
#[Map(source: FormCollection::class)]
final class Ehealth
{
    use EhealthMapping;

    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[deviceId]', transform: new FhirReference('device', includeText: true))]
    public array $device;

    #[Map(source: '[status]')]
    public mixed $status;

    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(source: '[recorded]', transform: 'convertToEHealthISO8601')]
    public string $recorded;

    #[Map(if: false)]
    public readonly array $context;

    #[Map(if: false)]
    public readonly array $recorder;

    #[Map(source: '[associationDate?]', if: [self::class, 'filled'], transform: [self::class, 'date'])]
    public string $associationDate;

    #[Map(source: '[bodySiteCode?]', if: [self::class, 'filled'], transform: [self::class, 'bodySite'])]
    public array $bodySite;

    #[Map(source: '[reportOriginCode?]', if: [self::class, 'nonPrimary'], transform: [self::class, 'reportOrigin'])]
    public array $reportOrigin;

    public function __construct(string $id, string $encounter, string $recorder)
    {
        $this->id = $id;
        $this->context = new FhirReference('encounter', includeText: true)($encounter, $this, null);
        $this->recorder = new FhirReference('employee', includeText: true)($recorder, $this, null);
    }

    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }

    public static function date(string $value): string
    {
        return CarbonImmutable::parse($value)->toDateString();
    }

    public static function bodySite(string $value, FormCollection $source): array
    {
        return new FhirCodeableConcept('eHealth/body_structures')($value, $source, null)
            + ['text' => $source['bodySiteText'] ?? ''];
    }

    public static function nonPrimary(mixed $value, FormCollection $source): bool
    {
        return !$source['primarySource'];
    }

    public static function reportOrigin(string $value, FormCollection $source): array
    {
        return new FhirCodeableConcept('eHealth/report_origins')($value, $source, null)
            + ['text' => $source['reportOriginText'] ?? ''];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        // Required scalar fields retain explicit nulls; the clinical contract keeps zero and false.
        $data['status'] = $this->status;
        $data['primary_source'] = $this->primarySource;
        $order = array_flip([
            'id', 'device', 'status', 'primary_source', 'recorded', 'context', 'recorder',
            'association_date', 'body_site', 'report_origin',
        ]);

        return array_replace(array_intersect_key($order, $data), $data);
    }
}
