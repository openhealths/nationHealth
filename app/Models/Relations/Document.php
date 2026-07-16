<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Casts\EHealthDateCast;
use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
        'documentable_id',
        'documentable_type'
    ];

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'type',
        'number',
        'issued_by',
        'issuing_country',
        'issued_at',
        'expiration_date',
        'active_to'
    ];

    protected $casts = [
        'issued_at' => EHealthDateCast::class,
        'expiration_date' => EHealthDateCast::class,
        'active_to' => EHealthDateCast::class
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
