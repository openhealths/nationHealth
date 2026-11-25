<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

use App\Dto\Identifier\Ehealth as IdentifierEhealth;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Project authorized episode and medical-record selections to API references. */
class CarePlanSupportingInfo implements TransformCallableInterface
{
    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        $references = [];
        foreach ($value as $episode) {
            $uuid = is_array($episode) ? ($episode['uuid'] ?? $episode['id'] ?? null) : $episode;
            $references[] = is_array($episode) && isset($episode['identifier'])
                ? $episode
                : ['identifier' => IdentifierEhealth::map([
                    'value' => $uuid,
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'episode_of_care']]],
                ])];
        }
        foreach (data_get($source, 'medicalRecords', []) as $record) {
            $references[] = isset($record['identifier']) ? $record : ['identifier' => IdentifierEhealth::map([
                'value' => $record['uuid'],
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $record['type']]]],
            ])];
        }

        return $references;
    }
}
