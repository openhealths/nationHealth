<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObservationComponent extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'observation_id',
        'code_id',
        'interpretation_id'
    ];

    protected $hidden = [
        'id',
        'observation_id',
        'authority_id',
        'code_id',
        'interpretation_id',
        'created_at',
        'updated_at'
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function value(): HasOne
    {
        return $this->hasOne(Value::class);
    }

    public function interpretation(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'interpretation_id');
    }

    public function referenceRanges(): HasMany
    {
        return $this->hasMany(ReferenceRange::class);
    }
}
