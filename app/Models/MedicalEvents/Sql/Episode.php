<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Person\EpisodeStatus;
use Eloquence\Behaviours\HasCamelCasing;
use App\Models\Person\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Episode extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'uuid',
        'person_id',
        'encounter_id',
        'episode_type_id',
        'status',
        'name',
        'managing_organization_id',
        'care_manager_id',
        'ehealth_inserted_at',
        'ehealth_updated_at'
    ];

    protected $hidden = [
        'id',
        'person_id',
        'encounter_id',
        'episode_type_id',
        'managing_organization_id',
        'care_manager_id',
        'updated_at'
    ];

    protected $casts = ['active' => EpisodeStatus::class];

    public function period(): MorphOne
    {
        return $this->morphOne(Period::class, 'periodable');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Coding::class, 'episode_type_id');
    }

    public function managingOrganization(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'managing_organization_id');
    }

    public function careManager(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'care_manager_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
