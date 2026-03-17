<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Casts\EHealthDateCast;
use App\Models\User;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\Auth;
use App\Traits\SyncsMorphManyRelations;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee\EmployeeRequest;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Party extends Model
{
    use HasCamelCasing;
    use SyncsMorphManyRelations;

    protected $fillable = [
        'uuid',
        'last_name',
        'first_name',
        'second_name',
        'birth_date',
        'gender',
        'tax_id',
        'no_tax_id',
        'about_myself',
        'working_experience',
        'declaration_count',
        'declaration_limit',
        'verification_status',
        'verification_status',
    ];

    protected $casts = [
        'birth_date' => EHealthDateCast::class,
    ];

    public $timestamps = false;

    /**
     * Get the party's full name.
     * This is an accessor, allowing you to use it like a property: $party->fullName
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        $fullName = trim($this->last_name . ' ' . $this->first_name);

        if (!empty($this->second_name)) {
            $fullName .= ' ' . $this->second_name;
        }

        return $fullName;
    }

    /**
     * Get the users associated with the party.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'party_id');
    }

    public function employeeRequests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class, 'party_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    /**
     * Checks whether a person has an active role as an Owner in a given institution.
     */
    public function hasActiveOwnerRole(int $legalEntityId): bool
    {
        return $this->employees()
            ->activeOwners($legalEntityId)
            ->exists();
    }
}
