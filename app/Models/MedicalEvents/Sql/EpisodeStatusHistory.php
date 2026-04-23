<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use App\Enums\Person\EpisodeStatus;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpisodeStatusHistory extends Model
{
    use HasCamelCasing;

    protected $table = 'episode_status_history';

    protected $fillable = [
        'episode_id',
        'status',
        'status_reason_id',
        'ehealth_inserted_by',
        'ehealth_inserted_at'
    ];

    protected $hidden = [
        'id',
        'episode_id',
        'status_reason_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'status' => EpisodeStatus::class,
        'ehealth_inserted_at' => EHealthTimestampCast::class
    ];

    public function statusReason(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'status_reason_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
