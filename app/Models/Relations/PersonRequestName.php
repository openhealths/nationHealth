<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Models\Person\PersonRequest;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonRequestName extends BaseName
{
    protected $table = 'person_request_names';

    protected $fillable = [
        'person_request_id',
        'language',
        'first_name',
        'last_name',
        'second_name',
        'no_last_name'
    ];

    /**
     * The person request this name belongs to.
     *
     * @return BelongsTo
     */
    public function personRequest(): BelongsTo
    {
        return $this->belongsTo(PersonRequest::class);
    }
}
