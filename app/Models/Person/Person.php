<?php

declare(strict_types=1);

namespace App\Models\Person;

use App\Models\ConfidantPersonRelationshipRequest;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\Relations\ConfidantPerson;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends BasePerson
{
    public function __construct()
    {
        parent::__construct();
        $this->mergeFillable(['verification_status', 'death_date']);
    }

    protected $table = 'persons';

    protected $hidden = [
        'patient_signed',
        'process_disclosure_data_consent',
        'created_at',
        'updated_at'
    ];

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function personRequest(): HasOne
    {
        return $this->hasOne(PersonRequest::class);
    }

    /**
     * How many people do I represent as a confidant person.
     *
     * @return HasMany
     */
    public function confidantFor(): HasMany
    {
        return $this->hasMany(ConfidantPerson::class, 'person_id');
    }

    /**
     * Who is MY confidant persons.
     *
     * @return HasMany
     */
    public function confidantPersons(): HasMany
    {
        return $this->hasMany(ConfidantPerson::class, 'subject_person_id');
    }

    /**
     * List of requests for adding confidant person.
     *
     * @return HasMany
     */
    public function confidantPersonRelationshipRequests(): HasMany
    {
        return $this->hasMany(ConfidantPersonRelationshipRequest::class, 'person_id');
    }
}
