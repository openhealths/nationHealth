<?php

declare(strict_types=1);

namespace App\Models\Relations;

use App\Casts\EHealthDateCast;
use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Person\{Person, PersonRequest};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ConfidantPerson extends Model
{
    protected $table = 'confidant_persons';

    protected $hidden = [
        'id',
        'person_request_id',
        'subject_person_id',
        'created_at',
        'updated_at'
    ];

    protected $fillable = [
        'uuid',
        'person_request_id',
        'person_id',
        'subject_person_id',
        'active_to',
        'sync_status'
    ];

    protected $casts = ['active_to' => EHealthDateCast::class];

    /**
     * Scope a query to filter confidant persons by legal entity ID.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  The query builder instance
     * @param  int  $legalEntityId  The ID of the legal entity to filter by
     * @return \Illuminate\Database\Eloquent\Builder The modified query builder instance
     */
    public function scopeFilterByLegalEntityId(Builder $query, int $legalEntityId): Builder
    {
        return $query->whereHas('subjectPerson', function (Builder $query) use ($legalEntityId) {
            $query->whereHas('declarations', function (Builder $query) use ($legalEntityId) {
                $query->where('legal_entity_id', $legalEntityId);
            });
        });
    }

    /**
     * Scope a query to filter confidant persons by their synchronization status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  The query builder instance
     * @param  \App\Enums\JobStatus  $status  The job status to filter by
     * @return \Illuminate\Database\Eloquent\Builder The modified query builder instance
     */
    public function scopeFilterBySyncStatus(Builder $query, JobStatus $status): Builder
    {
        return $query->where('sync_status', $status);
    }

    /**
     * Act as confidant for another person.
     *
     * @return BelongsTo
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Person who need confidant person (young or incapacitated).
     *
     * @return BelongsTo
     */
    public function subjectPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'subject_person_id');
    }

    public function personRequest(): BelongsTo
    {
        return $this->belongsTo(PersonRequest::class);
    }

    public function documentsRelationship(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Set the synchronization status for the confidant person.
     *
     * @param  JobStatus  $status  The job status to be set for synchronization
     * @return void
     */
    public function setSyncStatus(JobStatus $status): void
    {
        $this->sync_status = $status;
        $this->save();
    }
}
