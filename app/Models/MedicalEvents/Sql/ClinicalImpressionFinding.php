<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalImpressionFinding extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'clinical_impression_id',
        'item_reference_id',
        'basis'
    ];

    protected $hidden = [
        'id',
        'clinical_impression_id',
        'item_reference_id',
        'created_at',
        'updated_at'
    ];

    public function itemReference(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'item_reference_id');
    }
}
