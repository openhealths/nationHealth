<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Models\User;
use App\Casts\EHealthDateCast;
use App\Models\Employee\Employee;
use App\Traits\SyncsMorphManyRelations;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee\EmployeeRequest;
use Eloquence\Behaviours\HasCamelCasing;
use App\Models\ReorganizationEmployeeDeclaration;
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
     * @return Attribute
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $fullName = trim($this->lastName . ' ' . $this->firstName);

                if (!empty($this->secondName)) {
                    $fullName .= ' ' . $this->secondName;
                }

                return $fullName;
            }
        );
    }

    /**
     * Get the users associated with the party.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Users of this party that already have an employee record in the given legal entity.
     * Used when adding another position so email choice cannot cross facilities.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function usersWithEmployeeInLegalEntity(int $legalEntityId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->users()
            ->where(function ($query) use ($legalEntityId): void {
                $query->whereHas(
                    'employees',
                    fn ($employees) => $employees
                        ->where('legal_entity_id', $legalEntityId)
                        ->where('party_id', $this->id)
                )->orWhereIn(
                    'users.id',
                    Employee::query()
                        ->where('legal_entity_id', $legalEntityId)
                        ->where('party_id', $this->id)
                        ->whereNotNull('user_id')
                        ->select('user_id')
                );
            })
            ->oldest()
            ->get();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'party_id');
    }

    public function reorganizedEmployeeDeclarations(): HasMany
    {
        return $this->hasMany(ReorganizationEmployeeDeclaration::class, 'party_id');
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
}
