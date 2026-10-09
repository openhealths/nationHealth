<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\JobStatus;
use App\Models\EhealthJob;
use App\Models\Person\Person;
use App\Models\Preperson;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompositionOperation extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'composition_id', 'ehealth_job_id', 'remote_job_id', 'operation', 'composition_type',
        'person_id', 'preperson_id', 'encounter_uuid', 'episode_uuid', 'author_uuid',
    ];

    protected function casts(): array
    {
        return ['operation' => CompositionAsyncOperation::class, 'composition_type' => CompositionType::class];
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(EhealthJob::class, 'ehealth_job_id');
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->job->status) {
            JobStatus::COMPLETED->value => CompositionJobStatus::DONE->value,
            JobStatus::FAILED->value => CompositionJobStatus::FAILED->value,
            default => CompositionJobStatus::PENDING->value,
        });
    }

    protected function error(): Attribute
    {
        return Attribute::get(fn (): ?string => implode(' ', $this->job->response_data['errors'] ?? []) ?: null);
    }

    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->whereHas('job', fn (Builder $job) => $job->where('status', JobStatus::PENDING->value));
    }

    #[Scope]
    protected function forPatient(Builder $query, Person|Preperson $patient): Builder
    {
        return $query->where($patient instanceof Preperson ? 'preperson_id' : 'person_id', $patient->id);
    }
}
