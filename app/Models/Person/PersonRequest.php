<?php

declare(strict_types=1);

namespace App\Models\Person;

use App\Enums\Person\Status;
use App\Models\Relations\ConfidantPerson;
use App\Models\Relations\PersonRequestName;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonRequest extends BasePerson
{
    public function __construct()
    {
        parent::__construct();
        $this->mergeFillable(['status', 'person_id', 'authorize_with']);
        $this->mergeCasts(['status' => Status::class]);
    }

    protected static function boot(): void
    {
        parent::boot();

        // Cascade delete
        static::deleting(static function (PersonRequest $personRequest) {
            // Delete all confidant persons and their documents
            foreach ($personRequest->confidantPersons as $confidantPerson) {
                $confidantPerson->documentsRelationship()->delete();
                $confidantPerson->delete();
            }

            $personRequest->addresses()->delete();
            $personRequest->documents()->delete();
            $personRequest->phones()->delete();
            $personRequest->authenticationMethods()->delete();
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function confidantPersons(): HasMany
    {
        return $this->hasMany(ConfidantPerson::class);
    }

    /**
     * The person request's name groups, one per language.
     *
     * @return HasMany
     */
    public function names(): HasMany
    {
        return $this->hasMany(PersonRequestName::class);
    }
}
