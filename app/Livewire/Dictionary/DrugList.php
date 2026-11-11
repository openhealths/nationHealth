<?php

declare(strict_types=1);

namespace App\Livewire\Dictionary;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalProgram\Type;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Traits\FormTrait;
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
    public string $filterMedicalProgramId = '';

    /**
     * Filter by name.
     *
     * @var string
     */
    public string $filterInnmDosageName = '';

    /**
     * Filter by local name in ingredients.*.name array.
     *
     * @var string
     */
    public string $filterInnmName = '';

    /**
     * Filter by MEDICATION_FORM keys.
     *
     * @var string
     */
    public string $filterInnmDosageForm = '';

    /**
     * Filter by ATC code.
     *
     * @var string
     */
    public string $filterMedicationCodeAtc = '';

    /**
     * Filter by MR_BLANK_TYPES keys.
     *
     * @var string
     */
    public string $filterMrBlankType = '';

    public array $dictionaryNames = ['MEDICATION_UNIT', 'MEDICATION_FORM', 'MR_BLANK_TYPES'];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $this->programs = dictionary()->medicalPrograms()
            ->where('is_active', true)
            ->where('type', Type::MEDICATION)
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
            [
                'filterMedicalProgramId',
                'filterInnmDosageName',
                'filterInnmName',
                'filterInnmDosageForm',
                'filterMedicationCodeAtc',
                'filterMrBlankType'
            ]
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
            $this->validate(['filterMedicalProgramId' => 'required']);
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
        if (empty($this->filterMedicalProgramId)) {
            return new LengthAwarePaginator([], 0, config('pagination.per_page'), 1);
        }

        $perPage = config('pagination.per_page');
        $currentPage = Paginator::resolveCurrentPage();

        $filters = [
            'medical_program_id' => $this->filterMedicalProgramId,
            'page' => $currentPage,
            'page_size' => $perPage
        ];

        // Filters
        if (!empty($this->filterInnmDosageName)) {
            $filters['innm_dosage_name'] = $this->filterInnmDosageName;
        }

        if (!empty($this->filterInnmName)) {
            $filters['innm_name'] = $this->filterInnmName;
        }

        if (!empty($this->filterInnmDosageForm)) {
            $filters['innm_dosage_form'] = $this->filterInnmDosageForm;
        }

        if (!empty($this->filterMedicationCodeAtc)) {
            $filters['medication_code_atc'] = $this->filterMedicationCodeAtc;
        }

        if (!empty($this->filterMrBlankType)) {
            $filters['mr_blank_type'] = $this->filterMrBlankType;
        }

        try {
            $response = EHealth::drug()->getMany($filters);
            $rows = $response->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', __('errors.ehealth.messages.response_contract_invalid'));

            return new LengthAwarePaginator([], 0, $perPage, 1);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for drugs list.');

            return new LengthAwarePaginator([], 0, $perPage, 1);
        }

        return new LengthAwarePaginator(
            collect($rows),
            $response->getPaging()['total_entries'],
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
        return view('livewire.dictionary.drug-list', ['drugs' => $this->drugs]);
    }
}
