<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DeviceProperty extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'device_id',
        'code_id'
    ];

    protected $hidden = [
        'id',
        'device_id',
        'code_id',
        'created_at',
        'updated_at'
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'code_id');
    }

    public function value(): HasOne
    {
        return $this->hasOne(Value::class);
    }
}
