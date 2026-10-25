<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use App\Core\Arr;
use Illuminate\Support\Facades\Validator;
use App\Services\MedicalEvents\EHealthJobResolver;

trait MapsCarePlanResources
{
    /** Normalize the documented response envelope once, without losing read-only fields. */
    public function resourceFromResponse(array $data): array
    {
        $data = Arr::toCamelCase($data);
        if (isset($data['uuid']) && !isset($data['id'])) {
            $data['id'] = $data['uuid'];
            unset($data['uuid']);
        }
        Validator::make($data, ['id' => ['required', 'uuid']])->validate();

        return $data;
    }

    /** Preserve false and zero. Signed remote snapshots never pass through this formatter. */
    protected function resourcePayload(array $data): array
    {
        $result = [];
        foreach (Arr::toSnakeCase($data) as $key => $value) {
            if (is_array($value)) {
                $value = $this->resourcePayload($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $result[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($result) : $result;
    }

    protected function resolveWriteResponse(EHealthResponse $response): array
    {
        return app(EHealthJobResolver::class)->resolve($response->getData());
    }
}
