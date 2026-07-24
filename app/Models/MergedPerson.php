<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Person\MergedPersonStatus;
use App\Models\Person\Person;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergedPerson extends Model
{
    use HasCamelCasing;

    protected $table = 'merged_persons';

    protected $fillable = [
        'uuid',
        'person_id',
        'merge_person_id',
        'status',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = ['status' => MergedPersonStatus::class];

    /**
     * The identified patient the person was merged into.
     *
     * @return BelongsTo
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * The preperson that was merged into the identified patient.
     *
     * @return BelongsTo
     */
    public function mergePerson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class, 'merge_person_id');
    }
}
