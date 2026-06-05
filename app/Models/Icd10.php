<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Icd10 extends Model
{
    protected $table = 'icd_10';

    protected $fillable = [
        'code',
        'description',
        'is_active',
        'child_values'
    ];

    /**
     * Filter by a case-insensitive partial match.
     * Latin input is matched against the code, Cyrillic input against the description.
     *
     * @param  Builder  $query
     * @param  string  $term
     * @return Builder
     */
    #[Scope]
    protected function search(Builder $query, string $term): Builder
    {
        $column = preg_match('/\p{Cyrillic}/u', $term) === 1 ? 'description' : 'code';

        return $query->where($column, 'ILIKE', "%$term%");
    }

    /**
     * Limit the query to active codes only.
     *
     * @param  Builder  $query
     * @return Builder
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereIsActive(true);
    }
}
