<?php

declare(strict_types=1);

namespace App\Models\MedicalEvents\Sql;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Identifier extends Model
{
    protected $fillable = [
        'value',
        'display_value'
    ];

    protected $appends = ['identifier'];

    protected $hidden = [
        'id',
        'type',
        'value',
        'pivot',
        'created_at',
        'updated_at'
    ];

    protected function identifier(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                'type' => $this->type->first() ? [
                    'coding' => $this->type->first()->coding->toArray(),
                    'text' => $this->type->first()->text
                ] : null,
                'value' => $this->value
            ]
        );
    }

    public function type(): MorphMany
    {
        return $this->morphMany(CodeableConcept::class, 'codeable_conceptable');
    }
}
