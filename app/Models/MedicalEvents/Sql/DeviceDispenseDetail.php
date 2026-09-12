<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDispenseDetail extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'device_dispense_id',
        'device_id',
        'device_code_id',
        'program_device_id',
        'quantity_id',
        'sell_price',
        'reimbursement_amount',
        'discount_amount'
    ];

    protected $casts = [
        'sell_price' => 'float',
        'reimbursement_amount' => 'float',
        'discount_amount' => 'float'
    ];

    protected $hidden = [
        'id',
        'device_dispense_id',
        'device_id',
        'device_code_id',
        'program_device_id',
        'quantity_id',
        'created_at',
        'updated_at'
    ];

    public function deviceDispense(): BelongsTo
    {
        return $this->belongsTo(DeviceDispense::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'device_id');
    }

    public function deviceCode(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'device_code_id');
    }

    public function programDevice(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'program_device_id');
    }

    public function quantity(): BelongsTo
    {
        return $this->belongsTo(Quantity::class);
    }
}