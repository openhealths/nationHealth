<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalGrantedResourceType extends Model
{
    protected $table = 'approval_granted_resource_types';

    protected $fillable = [
        'approval_id',
        'codeable_concept_id'
    ];

    public function codeableConcept(): BelongsTo
    {
        return $this->belongsTo(CodeableConcept::class, 'codeable_concept_id');
    }

    /**
     * Approval associated with the granted resource type.
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
