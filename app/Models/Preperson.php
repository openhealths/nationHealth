<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EHealthDateCast;
use App\Enums\Person\Gender;
use App\Enums\Preperson\Status;
use App\Models\MedicalEvents\Sql\Episode;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Preperson extends Model
{
    use HasCamelCasing;

    protected $table = 'prepersons';

    protected $fillable = [
        'uuid',
        'external_id',
        'first_name',
        'last_name',
        'second_name',
        'gender',
        'birth_date',
        'emergency_contact',
        'death_date',
        'note',
        'reason_context',
        'status',
        'ehealth_inserted_at',
        'ehealth_inserted_by',
        'ehealth_updated_at',
        'ehealth_updated_by'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'gender' => Gender::class,
        'emergency_contact' => 'array',
        'reason_context' => 'array',
        'status' => Status::class,
        'birth_date' => EHealthDateCast::class
    ];

    /**
     * Get the preperson's full name.
     *
     * @return Attribute
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("$this->lastName $this->firstName $this->secondName")
        );
    }

    /**
     * Episodes registered for this preperson.
     *
     * @return HasMany
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    /**
     * Merge requests raised to attach this preperson's records to an identified patient.
     *
     * @return HasMany
     */
    public function mergeRequests(): HasMany
    {
        return $this->hasMany(MergeRequest::class, 'merge_person_id')->latest('ehealth_inserted_at');
    }

    /**
     * User who registered the preperson in eHealth, resolved from the inserted_by UUID.
     *
     * @return BelongsTo
     */
    public function insertedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ehealth_inserted_by', 'uuid');
    }

    /**
     * User who last updated the preperson in eHealth, resolved from the updated_by UUID.
     *
     * @return BelongsTo
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ehealth_updated_by', 'uuid');
    }

    /**
     * Build the eHealth external_id following the mask "A.B.C":
     * A — EDRPOU of the MIS, B — EDRPOU (RNOKPP) of the current legal entity (NMP),
     * C — this record's internal identifier (its primary key, assigned on registration).
     *
     * @return string
     */
    public function buildExternalId(): string
    {
        return sprintf(
            '%s.%s.%d',
            config('ehealth.api.mis_edrpou'),
            legalEntity()->edrpou,
            $this->id
        );
    }
}
