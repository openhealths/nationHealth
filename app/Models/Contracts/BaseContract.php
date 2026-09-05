<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

/**
 * Basic abstraction for contracts and applications.
 * Defines common fields, castes, and logic.
 */
abstract class BaseContract extends Model
{
    use HasCamelCasing;

    // Disable standard timestamps, because you have your own column names (inserted_at, ehealth_inserted_at)
    public $timestamps = false;

    /**
     * Common attribute casts.
     * Converts raw database values to appropriate PHP types (e.g., Carbon, Array, Boolean).
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'nhs_signed_date' => 'date',

        // JSON fields - explicit casting is required to prevent "Array to string conversion" errors
        'contractor_payment_details' => 'array',
        'contractor_divisions' => 'array',
        'external_contractors' => 'array',
        'data' => 'array',
        'medical_programs' => 'array',

        'external_contractor_flag' => 'boolean',
        'contractor_signed' => 'boolean',

        // Casting creation dates to make ->format() work in Blade
        'inserted_at' => 'datetime',
        'updated_at' => 'datetime',
        'ehealth_inserted_at' => 'datetime',
        'ehealth_updated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Shared fillable fields
     */
    protected $fillable = [
        'uuid',
        'contract_number',
        'status',
        'status_reason',
        'type',
        'start_date',
        'end_date',
        'id_form',
        'issue_city',
        'contractor_base',
        'contractor_payment_details',
        'contractor_rmsp_amount',
        'contractor_divisions',
        'external_contractor_flag',
        'external_contractors',
        'nhs_signer_id',
        'nhs_signer_base',
        'nhs_contract_price',
        'nhs_payment_method',
        'nhs_signed_date',
        'misc',
        'data',
        'inserted_at',
        'updated_at',
        'contract_id',
        'medical_programs'
    ];
}
