<?php

declare(strict_types=1);

namespace App\Livewire\Contract\Forms;

use App\Rules\InDictionary;
use Carbon\CarbonImmutable;

class CapitationContractRequestForm extends BaseContractRequestForm
{
    protected const int CAPITATION_CONTRACT_MAX_PERIOD_DAY = 366;

    public int $contractorRmspAmount;

    public array $contractorDivisions;
    public bool $externalContractorFlag;
    public array $externalContractors;

    public function rules(): array
    {
        $parentRules = parent::rules();

        $parentRules['endDate'][] = function ($attribute, $value, $fail) {
            $startDate = CarbonImmutable::parse($this->startDate);
            $endDate = CarbonImmutable::parse($value);

            if ($startDate->diffInDays($endDate) > self::CAPITATION_CONTRACT_MAX_PERIOD_DAY) {
                $fail(
                    'різниця між датою закінчення договору та датою початку договору '
                    . 'не повинна перевищувати ' . self::CAPITATION_CONTRACT_MAX_PERIOD_DAY . ' днів'
                );
            }
        };

        return array_merge($parentRules, [
            'contractorRmspAmount' => ['required', 'integer:strict'],
            'idForm' => ['required', new InDictionary('CONTRACT_TYPE')],
            'contractorDivisions.*' => ['array', 'uuid', 'distinct'],
            'externalContractorFlag' => ['nullable', 'boolean'],
            'externalContractors' => ['nullable', 'array'],
            'externalContractors.legalEntityId' => ['required', 'uuid', 'exists:legal_entities,uuid'],
            'externalContractors.contract' => ['required', 'array'],
            'externalContractors.contract.number' => ['required', 'string', 'max:255'],
            'externalContractors.contract.issuedAt' => ['required', 'date_format:' . config('app.date_format')],
            'externalContractors.contract.expiresAt' => ['required', 'date_format:' . config('app.date_format')],
            'externalContractors.divisions' => ['required', 'array'],
            'externalContractors.divisions.id' => ['required', 'uuid', 'exists:divisions,uuid'],
            'externalContractors.divisions.medicalService' => ['required'],
        ]);
    }
}
