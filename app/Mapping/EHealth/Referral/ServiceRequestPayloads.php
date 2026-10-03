<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class ServiceRequestPayloads
{
    // Keep the legacy KEP byte order independently of reflection's inheritance order.
    private const array FIELD_ORDER = [
        'status', 'intent', 'priority', 'code', 'requester_employee', 'requester_legal_entity',
        'based_on', 'context', 'category', 'quantity', 'occurrence_period', 'supporting_info',
        'reason_reference', 'patient_instruction', 'inform_with', 'id', 'program',
    ];

    private readonly Serializer $serializer;

    public function __construct(private readonly ObjectMapperInterface $mapper)
    {
        $this->serializer = new Serializer([new ObjectNormalizer()]);
    }

    public function prequalify(ServiceRequestInput $input): array
    {
        $source = (object) [
            'request' => $input,
            'programs' => $input->programId === null ? [] : [(object) ['type' => 'medical_program', 'uuid' => $input->programId]],
        ];
        $payload = $this->normalize($this->mapper->map($source, EHealthServiceRequestPrequalify::class));
        $payload['service_request'] = $this->orderBody($payload['service_request']);

        return $payload;
    }

    public function signedCreate(ServiceRequestInput $input): array
    {
        return $this->orderBody($this->normalize($this->mapper->map($input, EHealthServiceRequestCreate::class)));
    }

    private function normalize(object $payload): array
    {
        return $this->serializer->normalize($payload, context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
    }

    private function orderBody(array $body): array
    {
        return array_replace(array_intersect_key(array_fill_keys(self::FIELD_ORDER, null), $body), $body);
    }
}
