<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;

class ClinicalImpressionProblem extends Model
{
    use HasCamelCasing;

    protected $fillable = [
        'clinical_impression_id',
        'identifier_id'
    ];
}
