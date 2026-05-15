<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use App\Casts\EHealthTimestampCast;
use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class PaperReferral extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'requisition',
        'requester_legal_entity_name',
        'requester_legal_entity_edrpou',
        'requester_employee_name',
        'service_request_date',
        'note'
    ];

    protected $hidden = [
        'id',
        'paper_referralable_type',
        'paper_referralable_id',
        'created_at',
        'updated_at'
    ];

    protected $casts = ['service_request_date' => EHealthTimestampCast::class];
}
