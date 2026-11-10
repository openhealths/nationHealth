<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Decoders;

use App\Classes\eHealth\EHealthResponse;
use RuntimeException;

class DivisionJsonDecoder
{
    public function decode(EHealthResponse $response, bool $list = false): array
    {
        $data = $response->getData();

        if ($data === []) {
            return [];
        }

        // Decode a second copy with number tokens quoted to retain their original digits.
        // Skip JSON strings, including escaped quotes, so their contents stay intact.
        $quotedNumbers = preg_replace(
            <<<'REGEX'
            ~"(?:[^"\\]++|\\.)*+"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~
            REGEX,
            '"$0"',
            $response->body()
        );

        if ($quotedNumbers === null) {
            throw new RuntimeException('Could not read Division coordinates: ' . preg_last_error_msg());
        }

        $flags = EHealthResponse::$defaultJsonDecodingFlags | JSON_THROW_ON_ERROR;
        $exactData = json_decode($quotedNumbers, true, flags: $flags)[EHealthResponse::DATA_PATH];

        if (!$list) {
            return $this->restoreCoordinates($data, $exactData);
        }

        foreach ($data as $key => $division) {
            $data[$key] = $this->restoreCoordinates($division, $exactData[$key]);
        }

        return $data;
    }

    protected function restoreCoordinates(mixed $division, mixed $exactDivision): mixed
    {
        foreach (['latitude', 'longitude'] as $axis) {
            if (\is_float($division['location'][$axis] ?? null)) {
                $division['location'][$axis] = $exactDivision['location'][$axis];
            }
        }

        return $division;
    }
}
