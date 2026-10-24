<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Coding extends Model
{
    protected $fillable = [
        'system',
        'code'
    ];

    protected $hidden = [
        'id',
        'codeable_type',
        'codeable_id',
        'created_at',
        'updated_at',
        'display',
        'version',
        'user_selected',
    ];

    public function codeable(): MorphTo
    {
        return $this->morphTo();
    }
}
