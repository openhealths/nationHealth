<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class DrugList extends Component
{
    use FormTrait;
    use WithPagination;

    /**
     * List of programs for choosing 'medical_program_id'
     *
     * @var array
     */
    public array $programs;

    /**
     * Selected program uuid for searching drug list. Required param.
     *
     * @var string
     */
    public string $selectedProgram = '';

    /**
     * Filter by name.
     *
     * @var string
     */
    public string $innmDosageName = '';

    /**
     * Filter by local name in ingredients.*.name array.
     *
     * @var string
     */
    public string $innmName = '';

    /**
     * Filter by MEDICATION_FORM keys.
     *
     * @var string
     */
    public string $innmDosageForm = '';

    /**
     *
     *
     * @var string
     */
    public string $medicationCodeAtc = '';

    /**
     * Filter by MR_BLANK_TYPES keys.
     *
     * @var string
     */
    public string $mrBlankType = '';

    public array $dictionaryNames = ['MEDICATION_UNIT', 'MEDICATION_FORM', 'MR_BLANK_TYPES'];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $this->programs = dictionary()->medicalPrograms()
            ->where('is_active', true)
            ->map(fn (array $program) => [
                'id' => $program['id'],
                'name' => $program['name']
            ])
            ->toArray();
    }

    /**
     * Reset available filters.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->reset(
            ['selectedProgram', 'innmDosageName', 'innmName', 'innmDosageForm', 'medicationCodeAtc', 'mrBlankType']
        );
    }

    /**
     * Helper function for showing translation.
     *
     * @param  string  $unit
     * @return string
     */
    public function getMedicationUnit(string $unit): string
    {
        return $this->dictionaries['MEDICATION_UNIT'][$unit];
    }

    public function search(): void
    {
        try {
            $this->validate(['selectedProgram' => 'required']);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->resetPage();
    }

    #[Computed]
    public function drugs(): LengthAwarePaginator
    {
        if (empty($this->selectedProgram)) {
            return new LengthAwarePaginator([], 0, config('pagination.per_page'), 1);
        }

        $filters = ['medical_program_id' => $this->selectedProgram];

        // Filters
        if (!empty($this->innmDosageName)) {
            $filters['innm_dosage_name'] = $this->innmDosageName;
        }

        if (!empty($this->innmName)) {
            $filters['innm_name'] = $this->innmName;
        }

        if (!empty($this->innmDosageForm)) {
            $filters['innm_dosage_form'] = $this->innmDosageForm;
        }

        if (!empty($this->medicationCodeAtc)) {
            $filters['medication_code_atc'] = $this->medicationCodeAtc;
        }

        if (!empty($this->mrBlankType)) {
            $filters['mr_blank_type'] = $this->mrBlankType;
        }

        try {
            $drugsData = collect(EHealth::drug()->getMany($filters)->getData());
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when searching for drugs list.');

            return new LengthAwarePaginator([], 0, config('pagination.per_page'), 1);
        }

        $perPage = config('pagination.per_page');
        $currentPage = Paginator::resolveCurrentPage();
        $currentPageItems = $drugsData->forPage($currentPage, $perPage);

        return new LengthAwarePaginator(
            $currentPageItems->values(),
            $drugsData->count(),
            $perPage,
            $currentPage,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page'
            ]
        );
    }

    public function render(): View
    {
        return view('livewire.dictionary.drug-list', [
            'drugs' => $this->drugs
        ]);
    }
}
