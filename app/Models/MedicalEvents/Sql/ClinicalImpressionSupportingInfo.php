<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class ClinicalImpressionSupportingInfo extends Model
{
    use HasCamelCasing;

    protected $table = 'clinical_impression_supporting_info';

    protected $fillable = [
        'clinical_impression_id',
        'identifier_id'
    ];
}
