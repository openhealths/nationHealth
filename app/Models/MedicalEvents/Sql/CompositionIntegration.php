<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompositionIntegration extends Model
{
    protected $fillable = [
        'component', 'type', 'integration_status', 'task_status',
        'record_number', 'status_message', 'ehealth_updated_at',
    ];

    protected function casts(): array
    {
        return ['ehealth_updated_at' => 'immutable_datetime'];
    }

    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    public function toDetail(): array
    {
        return [
            'component' => $this->component,
            'type' => $this->type,
            'integrationStatus' => $this->integration_status,
            'taskStatus' => $this->task_status,
            'details' => ['SL_NUM' => $this->record_number],
            'statusMessage' => $this->status_message,
            'updatedAt' => $this->ehealth_updated_at?->toIso8601String(),
        ];
    }
}
