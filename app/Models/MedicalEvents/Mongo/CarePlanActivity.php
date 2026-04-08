<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Mongo;

// use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\Model;

/**
 * MongoDB Model for Care Plan Activity FHIR responses.
 */
class CarePlanActivity extends Model
{
    // protected $connection = 'mongodb';
    protected $guarded = [];
}
