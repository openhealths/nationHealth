<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Forms;

use App\Core\BaseForm;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionType;
use Illuminate\Validation\Rule;

/**
 * Input for a birth conclusion (МВН).
 *
 * Turning this into a createComposition body is
 * {@see \App\Services\MedicalEvents\Mappers\CompositionMapper::newborn()}.
 */
class CompositionForm extends BaseForm
{
    public string $type = CompositionType::NEWBORN->value;

    public string $category = CompositionCategory::LIVE_BIRTH->value;

    /** eHealth UUID of the newborn (subject). */
    public string $prepersonUuid = '';

    public string $encounterUuid = '';

    /** eHealth UUID of the mother (section.focus). */
    public string $personUuid = '';

    public ?string $informWithUuid = null;

    public string $newbornBirthDate = '';

    public string $newbornSex = '';

    /**
     * @param  array<string, string>  $allowedSexes
     */
    public function compositionRules(array $allowedSexes = []): array
    {
        return [
            'type' => ['required', Rule::in([CompositionType::NEWBORN->value])],
            'category' => ['required', Rule::in([CompositionCategory::LIVE_BIRTH->value])],
            'prepersonUuid' => ['required', 'uuid'],
            'encounterUuid' => ['required', 'uuid'],
            'personUuid' => ['required', 'uuid'],
            'informWithUuid' => ['nullable', 'uuid'],
            'newbornBirthDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'newbornSex' => array_filter([
                'required',
                'string',
                $allowedSexes === [] ? null : Rule::in(array_keys($allowedSexes)),
            ]),
        ];
    }

    protected function rules(): array
    {
        return $this->compositionRules();
    }

    /**
     * @return array<string, mixed>
     */
    public function toMapperData(): array
    {
        return [
            'category' => $this->category,
            'prepersonUuid' => $this->prepersonUuid,
            'encounterUuid' => $this->encounterUuid,
            'personUuid' => $this->personUuid,
            'newbornBirthDate' => $this->newbornBirthDate,
            'newbornSex' => $this->newbornSex,
            'informWithUuid' => $this->informWithUuid,
        ];
    }

    public function resetCompositionFields(): void
    {
        $this->prepersonUuid = '';
        $this->encounterUuid = '';
        $this->personUuid = '';
        $this->informWithUuid = null;
        $this->newbornBirthDate = '';
        $this->newbornSex = '';
        $this->category = CompositionCategory::LIVE_BIRTH->value;
    }
}
