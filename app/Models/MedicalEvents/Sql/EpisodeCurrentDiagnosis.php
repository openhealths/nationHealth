<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpisodeCurrentDiagnosis extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'episode_id',
        'code_id',
        'condition_id',
        'role_id',
        'rank'
    ];

    protected $hidden = [
        'id',
        'episode_id',
        'code_id',
        'condition_id',
        'role_id',
        'created_at',
        'updated_at'
    ];

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'condition_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'role_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
