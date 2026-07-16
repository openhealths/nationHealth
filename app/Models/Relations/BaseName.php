<?php

declare(strict_types=1);

namespace App\Models\Relations;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

abstract class BaseName extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = ['no_last_name' => 'boolean'];
}
