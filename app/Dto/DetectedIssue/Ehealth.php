<?php

declare(strict_types=1);

namespace App\Dto\DetectedIssue;

use App\Dto\EhealthMapping;
use App\Dto\FormCollection;
use App\Enums\DetectedIssue\Status;
use App\Mapping\Conditions\SourceHasPath;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use stdClass;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** Validated encounter row; the caller supplies generated IDs and the authenticated recorder. */
#[Map(source: FormCollection::class)]
final class Ehealth
{
    use EhealthMapping;

    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = Status::PRELIMINARY->value;

    #[Map(source: '[subjectId]', transform: new FhirReference('device', includeText: true))]
    public array $subject;

    #[Map(if: false)]
    public readonly array $encounter;

    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(if: false)]
    public readonly array $recorder;

    #[Map(source: '[primarySource]', transform: [self::class, 'author'])]
    public array|stdClass $author;

    #[Map(source: '[code?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('detected_issue_codes', includeText: true))]
    public array $code;

    #[Map(source: '[detail?]', if: [self::class, 'filled'])]
    public mixed $detail;

    #[Map(source: '[identifiedDate?]', if: [self::class, 'hasDateTime'], transform: [self::class, 'dateTime'])]
    public string $identifiedDateTime;

    #[Map(source: '[implicatedId?]', if: [self::class, 'filled'], transform: new FhirReference('device', includeText: true))]
    public array $implicated;

    #[Map(source: '[basedOnId?]', if: [self::class, 'filled'], transform: new FhirReference('detected_issue', includeText: true))]
    public array $basedOn;

    #[Map(source: '[reportOriginCode?]', if: [self::class, 'nonPrimary'], transform: new FhirCodeableConcept('eHealth/report_origins', includeText: true))]
    public array $reportOrigin;

    public function __construct(string $id, string $encounter, string $recorder)
    {
        $this->id = $id;
        $this->encounter = new FhirReference('encounter', includeText: true)($encounter, $this, null);
        $this->recorder = new FhirReference('employee', includeText: true)($recorder, $this, null);
    }

    public static function author(mixed $primary, FormCollection $source, self $target): array|stdClass
    {
        return $primary
            ? new FhirReference('employee', includeText: true)($source['authorEmployeeId'] ?? $target->recorder['identifier']['value'], $source, $target)
            : new stdClass();
    }

    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }

    public static function hasDateTime(mixed $date, FormCollection $source): bool
    {
        return !empty($date) && !empty($source['identifiedTime']);
    }

    public static function dateTime(string $date, FormCollection $source): string
    {
        return convertToEHealthISO8601($date.' '.$source['identifiedTime']);
    }

    public static function nonPrimary(mixed $value, FormCollection $source): bool
    {
        return !$source['primarySource'];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        // Retain the old field order and explicit null status. Optional properties remain uninitialized.
        $data = ['id' => $data['id'], 'status' => $this->status] + $data;
        $data['primary_source'] = $this->primarySource;
        if (!$this->primarySource) {
            // ObjectNormalizer represents an empty stdClass as []; this API contract requires {}.
            $data['author'] = new stdClass();
        }

        $order = array_flip([
            'id', 'status', 'subject', 'encounter', 'primary_source', 'recorder', 'author',
            'code', 'detail', 'identified_date_time', 'implicated', 'based_on', 'report_origin',
        ]);

        return array_replace(array_intersect_key($order, $data), $data);
    }
}
