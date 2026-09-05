<?php

declare(strict_types=1);

namespace App\Models\Person;

use App\Enums\Person\AuthenticationMethod;
use App\Models\ConfidantPersonRelationshipRequest;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\Relations\ConfidantPerson;
use App\Models\Relations\PersonName;
use App\Models\Relations\PersonVerificationDetail;
use App\Models\MedicalEvents\Sql\Approval;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
     * The person's name groups, one per language.
     *
     * @return HasMany
     */
    public function names(): HasMany
    {
        return $this->hasMany(PersonName::class);
    }

    /**
     * The person's verification result per registry.
     *
     * @return HasMany
     */
    public function verificationDetails(): HasMany
    {
        return $this->hasMany(PersonVerificationDetail::class);
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

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /**
     * Whether the confidant person relationships tell that the person is themselves represented on any basis
     * other than a birth certificate. Being represented on such a basis bars the person from acting as a
     * legal representative in turn.
     *
     * @param  array  $relationships  Relationships as the Get Confidant Person relationships method returns them
     * @return bool
     */
    public static function isRepresentedByConfidant(array $relationships): bool
    {
        return collect($relationships)->contains(static function (array $relationship): bool {
            $isActive = empty($relationship['active_to'])
                || CarbonImmutable::parse($relationship['active_to'])->isFuture();

            return $isActive && !collect($relationship['documents_relationship'] ?? [])->contains(
                static fn (array $document): bool => in_array(
                    $document['type'] ?? null,
                    ['BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN'],
                    true
                )
            );
        });
    }

    /**
     * Whether the authentication methods hold an OTP method that is still active. A person without one cannot
     * act as a legal representative.
     *
     * @param  array  $authenticationMethods  Methods as the Get Person authentication methods method returns them
     * @return bool
     */
    public static function hasActiveOtpAuthenticationMethod(array $authenticationMethods): bool
    {
        return collect($authenticationMethods)->contains(static function (array $authenticationMethod): bool {
            if (($authenticationMethod['type'] ?? null) !== AuthenticationMethod::OTP->value) {
                return false;
            }

            return empty($authenticationMethod['ehealth_ended_at'])
                || CarbonImmutable::parse($authenticationMethod['ehealth_ended_at'])->isFuture();
        });
    }
}
