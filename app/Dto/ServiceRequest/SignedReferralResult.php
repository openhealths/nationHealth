<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Enums\EHealth\JobStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Mapping\Transforms\FallbackValue;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Metadata shared by signed service and device requests, including asynchronous envelopes. */
final class SignedReferralResult
{
    #[Map(source: 'id?')]
    public ?string $uuid = null;

    #[Map(source: 'status?', transform: [self::class, 'mapStatus'])]
    public string $status;

    #[Map(source: 'request_number?', transform: new FallbackValue('requisition'))]
    public ?string $request_number = null;

    public function toPatch(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }

    public static function mapStatus(mixed $value): string
    {
        return self::isClinicalStatus($value) ? $value : ServiceRequestStatus::ACTIVE->value;
    }

    public static function isClinicalStatus(mixed $value): bool
    {
        $status = strtolower((string) $value);

        return $status !== '' && JobStatus::tryFrom($status)?->isCreationEnvelopeStatus() !== true;
    }

    public static function entity(array $response): \stdClass
    {
        $result = $response['result'] ?? null;
        if (is_array($result)) {
            if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
                return (object) $result[0];
            }
            if (isset($result['data']) && is_array($result['data'])) {
                $data = $result['data'];

                return (object) (array_is_list($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : $data);
            }
            if (isset($result['id']) || isset($result['requisition']) || isset($result['request_number'])) {
                return (object) $result;
            }
        }
        if (isset($response['data']) && is_array($response['data'])) {
            $data = $response['data'];

            return (object) (array_is_list($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : $data);
        }

        return (object) $response;
    }
}
