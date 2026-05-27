<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
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
        try {
            $this->validate(['selectedDiagnoseGroup' => 'required']);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }
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
            $descriptions = DB::table('icd_10')
                ->whereIn('code', $codes)
                ->pluck('description', 'code');

            // Add descriptions to each code
            foreach ($details['diagnoses_group_codes'] as &$codeItem) {
                $codeItem['description'] = $descriptions->get($codeItem['code'], '');
            }

            return $details;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for group of diagnoses details.');

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
