<?php

namespace App\Models\MedicalEvents\Mongo;

use MongoDB\Laravel\Eloquent\Model;

class Approval extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'approvals';

    protected $guarded = [];
}
