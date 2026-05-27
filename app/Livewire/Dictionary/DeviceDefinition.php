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

class DeviceDefinition extends Component
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
     * List of classification types for filter dropdown
     *
     * @var array
     */
    public array $classificationTypes = [];

    /**
     * Selected program uuid for searching device definitions. Required param.
     *
     * @var string
     */
    public string $selectedProgram = '';

    /**
     * Filter by device name.
     *
     * @var string
     */
    public string $name = '';

    /**
     * Filter by model number.
     *
     * @var string
     */
    public string $modelNumber = '';

    /**
     * Filter by classification type code.
     *
     * @var string
     */
    public string $classificationTypeCode = '';

    public array $dictionaryNames = [
        'FUNDING_SOURCE',
        'SPECIALITY_TYPE',
        'device_definition_classification_type',
        'eHealth/assistive_devices',
        'device_definition_packaging_type',
        'device_unit'
    ];

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $this->dictionaries['eHealth/assistive_devices'] = dictionary()->basics()
            ->byName('eHealth/assistive_devices')
            ->flattenedChildValues()
            ->toArray();

        $this->programs = dictionary()->medicalPrograms()
            ->where('is_active', true)
            ->where('type', '=', Type::DEVICE)
            ->values()
            ->toArray();

        // Prepare classification types for filter dropdown
        $this->classificationTypes = [];

        // Add from eHealth/assistive_devices dictionary
        foreach ($this->dictionaries['eHealth/assistive_devices'] as $code => $name) {
            $this->classificationTypes[] = [
                'code' => $code,
                'name' => $name,
                'system' => 'eHealth/assistive_devices'
            ];
        }

        // Add from device_definition_classification_type dictionary
        foreach ($this->dictionaries['device_definition_classification_type'] as $code => $name) {
            $this->classificationTypes[] = [
                'code' => $code,
                'name' => $name,
                'system' => 'device_definition_classification_type'
            ];
        }
    }

    /**
     * Reset available filters.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->reset(['selectedProgram', 'name', 'classificationTypeCode', 'modelNumber']);
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
    public function deviceDefinitions(): LengthAwarePaginator
    {
        if (empty($this->selectedProgram)) {
            return new LengthAwarePaginator([], 0, config('pagination.per_page'), 1);
        }

        $filters = ['medical_program_id' => $this->selectedProgram];

        // Filters
        if (!empty($this->name)) {
            $filters['name'] = $this->name;
        }

        if (!empty($this->classificationTypeCode)) {
            $filters['classification_type_code'] = $this->classificationTypeCode;
        }

        if (!empty($this->modelNumber)) {
            $filters['model_number'] = $this->modelNumber;
        }

        try {
            $deviceDefinitionsData = collect(EHealth::deviceDefinition()->getMany($filters)->getData());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for device definitions list.');

            return new LengthAwarePaginator([], 0, config('pagination.per_page'), 1);
        }

        $perPage = config('pagination.per_page');
        $currentPage = Paginator::resolveCurrentPage();
        $currentPageItems = $deviceDefinitionsData->forPage($currentPage, $perPage);

        return new LengthAwarePaginator(
            $currentPageItems->values(),
            $deviceDefinitionsData->count(),
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
        return view('livewire.dictionary.device-definition', [
            'deviceDefinitions' => $this->deviceDefinitions
        ]);
    }
}
