<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Database\Factories\MedicalEvents\Sql\CodingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Coding extends Model
{
    /** @use HasFactory<CodingFactory> */
    use HasFactory;

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
    ];

    public function codeable(): MorphTo
    {
        return $this->morphTo();
    }
}
