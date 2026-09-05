<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Enums\Person\VerificationSource;
use App\Enums\Person\VerificationStatus;
use App\Models\Person\Person;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonVerificationDetail extends Model
{
    use HasCamelCasing;

    protected $table = 'person_verification_details';

    protected $fillable = [
        'person_id',
        'source',
        'verification_status',
        'verification_reason',
        'verification_comment',
        'result',
        'status'
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'source' => VerificationSource::class,
        'verification_status' => VerificationStatus::class
    ];

    /**
     * The person this verification detail belongs to.
     *
     * @return BelongsTo
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
