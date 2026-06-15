<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EHealthTimestampCast;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class ConfigurationMetadata extends Model
{
    use HasCamelCasing;

    protected $table = 'configuration_metadata';

    protected $fillable = [
        'resource',
        'resource_updated_at'
    ];

    protected $casts = ['resource_updated_at' => EHealthTimestampCast::class];
}
