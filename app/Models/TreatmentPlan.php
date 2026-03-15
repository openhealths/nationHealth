<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'person_id',
        'author_id',
        'legal_entity_id',
        'status',
        'category',
        'title',
        'period_start',
        'period_end',
        'terms_of_service',
        'encounter_id',
        'addresses',
        'description',
        'supporting_info',
        'note',
        'inform_with',
        'requisition',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'addresses' => 'array',
        'supporting_info' => 'array',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TreatmentPlanActivity::class);
    }
}
