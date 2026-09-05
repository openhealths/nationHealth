<?php

declare(strict_types=1);

namespace App\Casts\Division;

use App\Models\Division;
use App\Traits\WorkTimeUtilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class WorkingHours implements CastsAttributes
{
    use WorkTimeUtilities;

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $data = $value;

        $decodedValue = $value ? json_decode($value, true) : null;

        $data = array_replace($this->workingHours, $decodedValue ?: []);

        return $this->prepareWorkingHours($data, true);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $data = [];

        if (is_array($value)) {
            $data = [$key => $value];
        } else {
            $data = $value ? [$key => json_decode($value, true)] : [$key => [Division::getWorkingTimeTemplate()]];
        }

        return json_encode($this->prepareWorkingHours($data[$key]));
    }

    /**
     * Change divider between hours and minutes
     *
     * @param  array  $workingHours  // Array with work hours time data
     * @param  bool  $dotToColon  // Determine how divider must be switched
     * @return array
     */
    public function prepareWorkingHours(array $workingHours, bool $dotToColon = false): array
    {
        return $this->prepareTimeToRequest($workingHours, $dotToColon);
    }
}
