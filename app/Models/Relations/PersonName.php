<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Models\Person\Person;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonName extends BaseName
{
    protected $table = 'person_names';

    protected $fillable = [
        'person_id',
        'language',
        'first_name',
        'last_name',
        'second_name',
        'no_last_name'
    ];

    /**
     * The person this name belongs to.
     *
     * @return BelongsTo
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
