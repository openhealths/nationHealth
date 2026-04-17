<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    protected $table = 'approvals';

    protected $fillable = [
        'uuid',
        'approvable_id',
        'approvable_type',
        'granted_to_id',
        'granted_to_type',
        'granted_by_id',
        'status',
        'reason',
    ];

    /**
     * Get the parent approvable model (CarePlan, DiagnosticReport, etc.).
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Entity that was granted access.
     */
    public function grantedTo(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'granted_to_id');
    }
}
