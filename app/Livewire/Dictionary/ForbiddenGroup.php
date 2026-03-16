<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ForbiddenGroup extends Component
{
    use FormTrait;

    /**
     * List of available forbidden groups.
     *
     * @var array
     */
    public array $forbiddenGroups;

    /**
     * UUID of selected forbidden group for getting details.
     *
     * @var string
     */
    public string $selectedForbiddenGroup = '';

    public function mount(LegalEntity $legalEntity): void
    {
        $this->forbiddenGroups = dictionary()->forbiddenGroups()->toArray();
    }

    public function search(): void
    {
        try {
            $this->validate(['selectedForbiddenGroup' => 'required']);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }
    }

    #[Computed]
    public function forbiddenDetails(): array
    {
        if (empty($this->selectedForbiddenGroup)) {
            return [];
        }

        try {
            $details = EHealth::forbiddenGroup()->getDetails($this->selectedForbiddenGroup)->getData();

            // Get codes grouped by system
            $codesBySystem = collect($details['forbidden_group_codes'])
                ->groupBy('system')
                ->map(fn (Collection $codes) => $codes->pluck('code')->toArray());

            $descriptions = collect();

            // Get descriptions for each system separately
            foreach ($codesBySystem as $system => $codes) {
                $dictionaryName = $system === 'eHealth/ICD10_AM/condition_codes'
                    ? 'eHealth/ICD10_AM/condition_codes'
                    : 'eHealth/ICPC2/condition_codes';

                $systemDescriptions = dictionary()->basics()
                    ->byName($dictionaryName)
                    ->whereIn('code', $codes)
                    ->asCodeDescription();

                $descriptions = $descriptions->merge($systemDescriptions);
            }

            // Add descriptions to each code
            foreach ($details['forbidden_group_codes'] as &$codeItem) {
                $codeItem['description'] = $descriptions->get($codeItem['code'], '');
            }

            return $details;

        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when searching for forbidden group details.');

            return [];
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['selectedForbiddenGroup']);
    }

    public function render(): View
    {
        return view('livewire.dictionary.forbidden-group', [
            'forbiddenDetails' => $this->forbiddenDetails
        ]);
    }
}
