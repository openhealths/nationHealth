<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Eloquence\Behaviours\HasCamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Person\Person;
use App\Models\Employee\Employee;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Encounter;

class DeviceRequestRequest extends Model
{
    use HasCamelCasing;

    protected $table = 'device_request_requests';

    protected $fillable = [
        'uuid',
        'employee_id',
        'person_id',
        'division_id',
        'status',
        'request_number',
        'started_at',
        'ended_at',
        'device_id',
        'quantity',
        'program_id',
        'intent',
        'category',
        'based_on_id',
        'context_id',
        'priority',
        'note',
        'supporting_info'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'quantity' => 'decimal:2',
        'supporting_info' => 'array'
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(CarePlanActivity::class, 'based_on_id');
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'context_id');
    }
}
