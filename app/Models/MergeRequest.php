<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MergeRequest\Status;
use App\Models\Person\Person;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergeRequest extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'master_person_id',
        'merge_person_id',
        'status',
        'data_to_be_signed',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $hidden = [
        'id',
        'data_to_be_signed',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => Status::class,
        'data_to_be_signed' => 'array'
    ];

    /**
     * The preperson whose records are being merged into an identified patient.
     *
     * @return BelongsTo
     */
    public function mergePerson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class, 'merge_person_id');
    }

    /**
     * The identified patient the preperson's records are being merged into.
     *
     * @return BelongsTo
     */
    public function masterPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'master_person_id');
    }
}
