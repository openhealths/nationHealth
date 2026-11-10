<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Encoders;

use Brick\Math\BigDecimal;

class DivisionJsonEncoder
{
    /**
     * Serialize decimal coordinates as JSON numbers without a float conversion.
     *
     * @param  array<string, mixed>  $data
     */
    public function encode(array $data): string
    {
        $fields = [];

        foreach ($data as $key => $value) {
            if ($key === 'location' && \is_array($value)) {
                $coordinates = [];

                foreach ($value as $axis => $coordinate) {
                    if (\in_array($axis, ['latitude', 'longitude'], true) && $coordinate !== null && !\is_float($coordinate)) {
                        $decimal = \is_string($coordinate)
                            ? str_replace(',', '.', trim($coordinate, " \t\n\r\v\f"))
                            : $coordinate;
                        $number = (string) BigDecimal::of($decimal)->strippedOfTrailingZeros();
                    } else {
                        $number = json_encode($coordinate, JSON_THROW_ON_ERROR);
                    }

                    $coordinates[] = json_encode($axis, JSON_THROW_ON_ERROR) . ':' . $number;
                }

                $encoded = '{' . implode(',', $coordinates) . '}';
            } else {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            }

            $fields[] = json_encode($key, JSON_THROW_ON_ERROR) . ':' . $encoded;
        }

        return '{' . implode(',', $fields) . '}';
    }
}
