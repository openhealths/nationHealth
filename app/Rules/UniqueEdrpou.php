<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueEdrpou implements ValidationRule
{
    protected ?int $legalEntityId;

    protected ?int $legalEntityTypeId;

    protected ?string $legalEntityTypeName;

    protected ?int $selectedLegalEntityTypeId;

    public function __construct(string $legalEntityType = '')
    {
        // Check if user is authenticated
        $user = Auth::user();

        $this->legalEntityId = $user && legalEntity()
            ? legalEntity()->id
            : null;

        $this->legalEntityTypeId = $user && legalEntity()
            ? legalEntity()->type->id
            : null;

        $this->legalEntityTypeName = $user && legalEntity()
            ? legalEntity()->type->name
            : null;

        $this->selectedLegalEntityTypeId = LegalEntityType::where('name', $legalEntityType)->first()?->id ?? null;
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if value already exists in another legal entity, excluding the current entity
        $exists = LegalEntity::where('edrpou', $value)
            ->where('legal_entity_type_id', $this->selectedLegalEntityTypeId)
            ->when($this->legalEntityId !== null, function ($query) {
                $query->where('id', '<>', $this->legalEntityId); //Exclude the current entity
            })
            ->exists();

        // If it exists, fail the validation
        if ($exists) {
            $fail(__('validation.custom.unique_edrpou')); // Message for validation
        }
    }
}
