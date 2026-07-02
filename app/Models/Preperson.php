<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Person\Gender;
use App\Enums\Preperson\Status;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Preperson extends Model
{
    use HasCamelCasing;

    protected $table = 'prepersons';

    protected $fillable = [
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
        'status' => Status::class
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
