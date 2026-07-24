<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Casts\EHealthDateCast;
use Illuminate\Database\Eloquent\Model;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuthenticationMethod extends Model
{
    use HasCamelCasing;

    protected $hidden = [
        'id',
        'authenticatable_type',
        'authenticatable_id',
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'uuid',
        'authenticatable_type',
        'authenticatable_id',
        'type',
        'phone_number',
        'value',
        'alias',
        'ehealth_ended_at',
        'url'
    ];

    protected $casts = ['ehealth_ended_at' => EHealthDateCast::class];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to search for authentication method for a given model and UUID (if provided).
     *
     * @param  Builder  $query
     * @param  Model  $authenticatable
     * @param  string|null  $uuid
     * @return Builder
     */
    #[Scope]
    protected function getByModelAndUuid(Builder $query, Model $authenticatable, ?string $uuid = null): Builder
    {
        if (empty($uuid)) {
            return $query
                ->where('authenticatable_type', $authenticatable::class)
                ->where('authenticatable_id', $authenticatable->id);
        }

        return $query
            ->whereUuid($uuid)
            ->where('authenticatable_type', $authenticatable::class)
            ->where('authenticatable_id', $authenticatable->id);
    }
}
