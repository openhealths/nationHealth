<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Equipment\Type as DeviceNameType;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceName extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'device_id',
        'type',
        'value'
    ];

    protected $casts = [
        'type' => DeviceNameType::class
    ];

    protected $hidden = [
        'id',
        'device_id',
        'created_at',
        'updated_at'
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
