<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Casts\EHealthDateCast;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Speciality extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'id',
        'specialityable_id',
        'specialityable_type',
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'speciality',
        'speciality_officio',
        'level',
        'qualification_type',
        'attestation_name',
        'attestation_date',
        'valid_to_date',
        'certificate_number'
    ];

    protected $casts = [
        'speciality_officio' => 'boolean',
        'attestation_date' => EHealthDateCast::class,
        'valid_to_date' => EHealthDateCast::class,
    ];

    public function specialityable(): MorphTo
    {
        return $this->morphTo();
    }

    public function qualifications(): MorphMany
    {
        return $this->morphMany(Qualification::class, 'qualificationable');
    }
}
