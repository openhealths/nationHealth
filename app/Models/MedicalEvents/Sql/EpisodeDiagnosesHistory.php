<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EpisodeDiagnosesHistory extends Model
{
    use HasCamelCasing;

    protected $table = 'episode_diagnoses_history';

    protected $fillable = [
        'episode_id',
        'evidence_id',
        'date',
        'is_active'
    ];

    protected $hidden = [
        'id',
        'episode_id',
        'evidence_id',
        'created_at',
        'updated_at'
    ];

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'evidence_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(EpisodeDiagnosesHistoryItem::class, 'episode_diagnoses_history_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
