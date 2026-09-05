<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Enums\Status as EmployeeStatus;
use App\Livewire\Episode\Forms\EpisodeForm as Form;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Employee\Employee;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

abstract class BaseEpisodeComponent extends BasePatientComponent
{
    public Form $form;

    /**
     * Employees of the current legal entity that can be picked as a care manager.
     *
     * @var array
     */
    public array $employees = [];

    /**
     * Episode types allowed for the legal entity, keyed by code.
     *
     * @var array
     */
    public array $episodeTypes = [];

    /**
     * Codes of the episode types each care manager may use, keyed by employee UUID.
     *
     * @var array
     */
    public array $employeeEpisodeTypes = [];

    protected array $dictionaryNames = ['eHealth/episode_types', 'POSITION'];

    /**
     * Load the dictionaries and select options the form depends on.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->loadEmployees();
        $this->loadEpisodeTypes();
    }

    /**
     * Validate the form against the given rules, flashing the first error;
     * returns `null` when the data is invalid.
     *
     * @param  array  $rules
     * @return array|null
     */
    protected function validateForm(array $rules): ?array
    {
        try {
            return $this->form->validate($rules);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }
    }

    /**
     * Build the eHealth structure of the episode out of the validated form data.
     *
     * @param  array  $validated
     * @param  Status  $status
     * @return array
     */
    protected function formatEpisode(array $validated, Status $status): array
    {
        return Fhir::episode()->toFhir(
            $validated,
            ['episode' => $validated['id'], 'employee' => $validated['careManagerId']],
            $validated['startDate'],
            $validated['startTime'],
            $status
        );
    }

    /**
     * Leave the form and get back to the patient episode list.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->redirectToEpisodes();
    }

    /**
     * Get back to the patient episode list.
     *
     * @return void
     */
    protected function redirectToEpisodes(): void
    {
        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.episodes',
                [legalEntity(), 'preperson' => $this->prepersonId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute('persons.episodes', [legalEntity(), 'person' => $this->personId], navigate: true);
    }

    /**
     * Build the episode types allowed for the legal entity and, for every care manager, the subset of those
     * types their employee type may use. The subsets let the view narrow the list down without a request.
     *
     * @return void
     */
    protected function loadEpisodeTypes(): void
    {
        $this->episodeTypes = Arr::only(
            $this->dictionaries['eHealth/episode_types'],
            config('ehealth.legal_entity_episode_types.' . legalEntity()->type->name, [])
        );

        $legalEntityTypes = array_keys($this->episodeTypes);

        $this->employeeEpisodeTypes = collect($this->employees)
            ->mapWithKeys(static fn (array $employee): array => [
                $employee['uuid'] => array_values(array_intersect(
                    $legalEntityTypes,
                    config('ehealth.employee_episode_types.' . $employee['employeeType'], [])
                ))
            ])
            ->toArray();
    }

    /**
     * Get the active employees of the authenticated user within the current legal entity
     * that are allowed to be a care manager of an episode.
     *
     * @return void
     */
    protected function loadEmployees(): void
    {
        $this->employees = Auth::user()->party->employees()
            ->whereLegalEntityId(legalEntity()->id)
            ->whereIn('employee_type', config('ehealth.allowed_episode_care_manager_employee_types', []))
            ->whereStatus(EmployeeStatus::APPROVED)
            ->whereIsActive(true)
            ->select(['uuid', 'position', 'party_id', 'employee_type'])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position,
                'employeeType' => $employee->employeeType
            ])
            ->toArray();
    }
}
