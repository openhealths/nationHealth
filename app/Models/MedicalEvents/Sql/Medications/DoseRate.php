<?php

namespace App\Models\MedicalEvents\Sql\Medications;

use App\Models\MedicalEvents\Sql\Coding;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DoseRate extends Model
{
    use HasCamelCasing;

    protected $table = 'dose_rates';

    protected $fillable = [
        'type_id',
        'range_id',
        'rate_ratio'
    ];

    protected $hidden = [
        'id',
        'type_id',
        'range_id',
        'ratio_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'type_id' => 'integer',
        'range_id' => 'integer',
        'ratio_id' => 'integer'
    ];

    public function type(): MorphMany
    {
        return $this->MorphMany(Coding::class, 'codeable');
    }

    public function dosageInstruction(): HasOne
    {
        return $this->hasOne(DosageInstruction::class);
    }
}
