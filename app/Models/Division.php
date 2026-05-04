<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Status;
use App\Models\Relations\Phone;
use App\Casts\Division\Location;
use App\Models\Employee\Employee;
use App\Models\Relations\Address;
use App\Casts\Division\WorkingHours;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee\EmployeeRequest;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\Division\Type as DivisionType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Division extends Model
{
    use HasCamelCasing;

    public const float LOCATION_DEFAULT_LATITUDE = 0.0;
    public const float LOCATION_DEFAULT_LONGITUDE = 0.0;
    public const string WORKING_TIME_DEFAULT_START = '00:00';
    public const string WORKING_TIME_DEFAULT_END = '00:00';

    protected $fillable = [
        'uuid',
        'external_id',
        'name',
        'type',
        'mountain_group',
        'location',
        'email',
        'working_hours',
        'is_active',
        'legal_entity_id',
        'status',
    ];

    protected $casts = [
        'location' => Location::class,
        'healthcare_services' => 'array',
        'working_hours' => WorkingHours::class,
        'is_active' => 'boolean',
        'status' => Status::class
    ];

    protected $attributes = [
        'is_active' => false,
        'mountain_group' => false,
    ];

    /**
     * Returns an array of available division types.
     * Depends on the type of the associated LegalEntity.
     *
     * @return array
     */
    public static function getValidDivisionTypes(): array
    {
        $legalEntityType = legalEntity()?->type->name;

        if (!$legalEntityType) {
            return [];
        }

        return match ($legalEntityType) {
            LegalEntity::TYPE_EMERGENCY,
            LegalEntity::TYPE_OUTPATIENT => [
                DivisionType::TYPE_LICENSED_UNIT->value
            ],

            LegalEntity::TYPE_PHARMACY => [
                DivisionType::TYPE_DRUGSTORE->value,
                DivisionType::TYPE_DRUGSTORE_POINT->value
            ],

            default => [
                DivisionType::TYPE_FAP->value,
                DivisionType::TYPE_CLINIC->value,
                DivisionType::TYPE_AMBULANT_CLINIC->value
            ],
        };
    }

    /**
     * Returns an array of initial location values
     *
     * @return array
     */
    public static function getLocationTemplate(): array
    {
        return [
            'latitide' => self::LOCATION_DEFAULT_LATITUDE,
            'longitude' => self::LOCATION_DEFAULT_LONGITUDE
        ];
    }

    /**
     * Returns an array of initial WorkingTime values
     *
     * @return array
     */
    public static function getWorkingTimeTemplate(): array
    {
        return [
            self::WORKING_TIME_DEFAULT_START,
            self::WORKING_TIME_DEFAULT_END
        ];
    }

    /**
     * Returns an array of initial weekdays working time values
     *
     * @return array
     */
    public static function getWorkingDaysTemplate(): array
    {
        return [
            'mon' => [self::getWorkingTimeTemplate()],
            'tue' => [self::getWorkingTimeTemplate()],
            'wed' => [self::getWorkingTimeTemplate()],
            'thu' => [self::getWorkingTimeTemplate()],
            'fri' => [self::getWorkingTimeTemplate()],
            'sat' => [self::getWorkingTimeTemplate()],
            'sun' => [self::getWorkingTimeTemplate()]
        ];
    }

    /**
     * Returns an array of available LegalEntity types.
     *
     * @return array
     */
    public static function getValidLegalEntityTypes(): array
    {
        return [
            LegalEntity::TYPE_PRIMARY_CARE,
            LegalEntity::TYPE_OUTPATIENT,
            LegalEntity::TYPE_PHARMACY,
            LegalEntity::TYPE_EMERGENCY
        ];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeRequests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }

    public function healthcareServices(): HasMany
    {
        return $this->hasMany(HealthcareService::class);
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    /**
     * Scope a query to search for divisions by given search string.
     *
     * @param  Builder  $query
     * @param  string|null  $toSearch
     * @return Builder
     */
    #[Scope]
    public function search(Builder $query, ?string $toSearch): Builder
    {
        if (empty($toSearch)) {
            return $query;
        }

        return $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($toSearch) . '%']);
    }

    #[Scope]
    public function active(Builder $query): Builder
    {
        return $query->whereStatus(Status::ACTIVE);
    }
}
