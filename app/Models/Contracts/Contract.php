<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Enums\Contract\ContractStatus;
use App\Enums\JobStatus;
use App\Models\Contracts\BaseContract as BaseContractModel;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends BaseContractModel
{
    protected $table = 'contracts';

    public function __construct(array $attributes = [])
    {
        $this->mergeFillable([
            'legal_entity_id',
            'sync_status',
            'contract_id',
            'contract_request_id',
            'previous_request_id',
            'is_active',
            'is_suspended',
            'skip_provision_deactivation',
            'signed_content_location',
            'statute_md5',
            'additional_document_md5',
            'inserted_by',
            'inserted_at',
            'updated_by',
            'updated_at',
            'contractor_legal_entity_id',
            'contractor_owner_id',
            'nhs_legal_entity_id',
            'nhs_signer_id',
            'contractor_signed',
            'medical_programs',
        ]);

        parent::__construct($attributes);
    }

    protected $casts = [
        'is_active' => 'boolean',
        'is_suspended' => 'boolean',
        'skip_provision_deactivation' => 'boolean',
        'contractor_signed' => 'boolean',
        'external_contractor_flag' => 'boolean',
        'status' => ContractStatus::class,
        'sync_status' => JobStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'nhs_signed_date' => 'date',
        'inserted_at' => 'datetime',
        'updated_at' => 'datetime',
        'medical_programs' => 'array',
        'contractor_payment_details' => 'array',
        'contractor_divisions' => 'array',
        'external_contractors' => 'array',
        'data' => 'array',
    ];

    public function getIsSuspendedAttribute(): bool
    {
        return filter_var($this->data['is_suspended'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function scopeForLegalEntity(Builder $query, LegalEntity $legalEntity): Builder
    {
        return $query->where('legal_entity_id', $legalEntity->id);
    }
}
