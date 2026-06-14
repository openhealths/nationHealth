<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalGrantedResource extends Model
{
    protected $table = 'approval_granted_resources';

    protected $fillable = [
        'approval_id',
        'granted_to_id'
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
