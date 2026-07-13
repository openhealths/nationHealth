<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MergeRequest\Status;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergeRequest extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'preperson_id',
        'master_person_id',
        'merge_person_id',
        'status',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => Status::class
    ];

    /**
     * The preperson whose records are being merged into an identified patient.
     *
     * @return BelongsTo
     */
    public function preperson(): BelongsTo
    {
        return $this->belongsTo(Preperson::class);
    }
}
