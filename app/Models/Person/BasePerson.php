<?php

declare(strict_types=1);

namespace App\Models\Person;

use App\Casts\EHealthDateCast;
use App\Models\Relations\Address;
use App\Models\Relations\AuthenticationMethod;
use App\Models\Relations\Document;
use App\Models\Relations\Phone;
use Carbon\CarbonImmutable;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

abstract class BasePerson extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'uuid',
        'birth_date',
        'birth_country',
        'birth_settlement',
        'gender',
        'email',
        'no_tax_id',
        'tax_id',
        'secret',
        'unzr',
        'emergency_contact',
        'patient_signed',
        'process_disclosure_data_consent'
    ];

    protected $casts = [
        'emergency_contact' => 'array',
        'birth_date' => EHealthDateCast::class
    ];

    /**
     * Narrow the search down to persons having a name group that matches every given column.
     *
     * @param  Builder  $query
     * @param  array  $nameFilters  Keyed by person_names column, e.g. language, first_name, no_last_name
     * @return Builder
     */
    #[Scope]
    protected function forNameFilters(Builder $query, array $nameFilters): Builder
    {
        return $query->when($nameFilters, static function (Builder $query) use ($nameFilters): void {
            $query->whereHas('names', static function (Builder $query) use ($nameFilters): void {
                foreach ($nameFilters as $column => $value) {
                    $query->where($column, $value);
                }
            });
        });
    }

    /**
     * Narrow the search down to persons having a document that matches every given column.
     *
     * @param  Builder  $query
     * @param  array  $documentFilters  Keyed by documents column, i.e. type and number
     * @return Builder
     */
    #[Scope]
    protected function forDocumentFilters(Builder $query, array $documentFilters): Builder
    {
        return $query->when($documentFilters, static function (Builder $query) use ($documentFilters): void {
            $query->whereHas('documents', static function (Builder $query) use ($documentFilters): void {
                foreach ($documentFilters as $column => $value) {
                    $query->where($column, $value);
                }
            });
        });
    }

    /**
     * The primary name group: the Ukrainian entry when present, otherwise the first one.
     *
     * @return Attribute
     */
    protected function primaryName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->names->firstWhere('language', 'uk') ?? $this->names->first()
        );
    }

    /**
     * Get the person's full name, built from the primary name group.
     *
     * @return Attribute
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $name = $this->primaryName;

                return $name
                    ? trim($name->lastName . ' ' . $name->firstName . ' ' . $name->secondName)
                    : '';
            }
        );
    }

    /**
     * The person's name groups, one per language.
     *
     * @return HasMany
     */
    abstract public function names(): HasMany;

    protected function age(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->birth_date ? CarbonImmutable::parse($this->birth_date)->age : null
        );
    }

    public function addresses(): MorphMany
    {
        return $this->MorphMany(Address::class, 'addressable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    public function authenticationMethods(): MorphMany
    {
        return $this->morphMany(AuthenticationMethod::class, 'authenticatable');
    }
}
