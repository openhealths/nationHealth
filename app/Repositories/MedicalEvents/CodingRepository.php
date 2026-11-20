<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Dto\Coding\Model as CodingData;
use App\Models\MedicalEvents\Sql\Coding as SqlCoding;

class CodingRepository extends BaseRepository
{
    /**
     * Crate coding in DB by provided data.
     *
     * @param  CodingData|array  $coding
     * @return SqlCoding
     */
    public function store(CodingData|array $coding): SqlCoding
    {
        $attributes = $coding instanceof CodingData ? $coding->toArray() : $coding;

        return $this->model::create([
            'system' => $attributes['system'],
            'code' => $attributes['code']
        ]);
    }
}
