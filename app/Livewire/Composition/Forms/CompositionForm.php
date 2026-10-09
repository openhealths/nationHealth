<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Forms;

use App\Core\BaseForm;
use App\Livewire\Composition\Forms\Concerns\BuildsCompositionPayload;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionType;
use Illuminate\Validation\Rule;

class CompositionForm extends BaseForm
{
    use BuildsCompositionPayload;

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
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'newbornBirthDate' => __('compositions.fields.newborn_birth_date'),
            'newbornSex' => __('compositions.fields.newborn_sex'),
        ];
    }

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
            'newbornBirthDate' => ['required', 'date_format:'.config('app.date_format'), 'before_or_equal:today'],
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
    public function toPayload(string $authorEmployeeUuid): array
    {
        $payload = $this->base([
            'type' => CompositionType::NEWBORN,
            'category' => $this->category,
            'subjectUuid' => $this->prepersonUuid,
            'subjectResource' => 'preperson',
            'encounterUuid' => $this->encounterUuid,
            'authorEmployeeUuid' => $authorEmployeeUuid,
            'focusUuid' => $this->personUuid,
            'focusResource' => 'person',
            'periodStart' => $this->startOfDay($this->newbornBirthDate),
            'periodEnd' => null,
            'includeNullPeriodEnd' => true,
        ]);

        $payload['extension'] = array_merge($this->informWith($this->informWithUuid ?? null), [
            ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => $this->date($this->newbornBirthDate)],
            ['valueCode' => 'NEWBORN_SEX', 'valueString' => $this->newbornSex],
        ]);

        return $payload;
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
