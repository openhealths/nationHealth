<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ConditionDiagnose extends Component
{
    use FormTrait;

    /**
     * List of available diagnose groups.
     *
     * @var array
     */
    public array $diagnoseGroups;

    /**
     * UUID of selected diagnose group for getting details.
     *
     * @var string
     */
    public string $selectedDiagnoseGroup = '';

    public function mount(LegalEntity $legalEntity): void
    {
        $this->diagnoseGroups = dictionary()->diagnoseGroups()->toArray();
    }

    public function search(): void
    {
        $this->validate(['selectedDiagnoseGroup' => 'required']);
    }

    #[Computed]
    public function diagnoseDetails(): array
    {
        if (empty($this->selectedDiagnoseGroup)) {
            return [];
        }

        try {
            $details = EHealth::diagnoseGroup()->getDetails($this->selectedDiagnoseGroup)->getData();

            // Get only the codes we need descriptions for
            $codes = collect($details['diagnoses_group_codes'])->pluck('code')->toArray();

            // Get descriptions only for these specific codes
            $descriptions = dictionary()->basics()
                ->byName('eHealth/ICD10_AM/condition_codes')
                ->whereIn('code', $codes)
                ->asCodeDescription();

            // Add descriptions to each code
            foreach ($details['diagnoses_group_codes'] as &$codeItem) {
                $codeItem['description'] = $descriptions->get($codeItem['code'], '');
            }

            return $details;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when searching for group of diagnoses details.');

            return [];
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['selectedDiagnoseGroup']);
    }

    public function render(): View
    {
        return view('livewire.dictionary.condition-diagnose', [
            'diagnoseDetails' => $this->diagnoseDetails
        ]);
    }
}
