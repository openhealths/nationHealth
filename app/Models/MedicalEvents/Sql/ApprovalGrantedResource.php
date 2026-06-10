<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use App\Models\MedicalEvents\Sql\Identifier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalGrantedResource extends Model
{
    protected $table = 'approval_granted_resources';

    protected $fillable = [
        'approval_id',
        'granted_to_id'
    ];

    /**
     * Entity that has granted access.
     */
    public function grantedTo(): BelongsTo
    {
        return $this->belongsTo(Identifier::class, 'granted_to_id');
    }

    /**
     * Approval associated with the granted resource.
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
