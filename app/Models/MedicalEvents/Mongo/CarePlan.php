<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Mongo;

// use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\Model;

/**
 * MongoDB Model for Care Plan FHIR responses.
 * Ready for NoSQL migration as per team lead's requirements.
 */
class CarePlan extends Model
{
    // protected $connection = 'mongodb';
    protected $guarded = [];
}
