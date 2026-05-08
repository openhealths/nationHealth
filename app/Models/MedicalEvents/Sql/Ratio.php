<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ratio extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'id',
        'numerator_id',
        'denominator_id',
        'created_at',
        'updated_at'
    ];

    public function numerator(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'numerator_id');
    }

    public function denominator(): BelongsTo
    {
        return $this->belongsTo(Quantity::class, 'denominator_id');
    }
}
