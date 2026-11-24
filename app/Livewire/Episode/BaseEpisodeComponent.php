<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\Episode\Ehealth as EhealthData;
use App\Enums\Status as EmployeeStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\Episode\Forms\EpisodeForm as Form;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Episode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Throwable;

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
     * Leave the form and get back to the patient episode list.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->redirectToEpisodes();
    }

    /**
     * Submit the stored draft to eHealth, built from the form, and follow the job it is processed with.
     * The episode stays a draft until the job is processed, so a rejected one can be fixed and submitted again.
     *
     * @param  Episode  $episode
     * @return void
     */
    protected function submitToEHealth(Episode $episode): void
    {
        $requestData = objectMapper()->map($this->form, EhealthData::class)->toArray();

        try {
            $submission = RemoteEHealthLinksProcessing::startSubmission($episode, $requestData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while starting episode submission');
            $this->redirectToEpisode($episode->id);

            return;
        }

        try {
            $response = EHealth::episode()->create($this->uuid, $requestData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while creating episode');
            RemoteEHealthLinksProcessing::markSubmissionFailed($submission);
            $this->redirectToEpisode($episode->id);

            return;
        }

        // The request is received already, so the episode stays submitted and locked even if following the job fails
        try {
            RemoteEHealthLinksProcessing::track($submission, $response->validate());

            Session::flash('success', __('episodes.messages.create_request_sent'));
        } catch (Throwable $exception) {
            Log::channel('e_health_errors')->error('Submitted episode is not tracked', [
                'episodeId' => $episode->id,
                'statusCode' => $response->getStatusCode(),
                'exception' => $exception->getMessage()
            ]);
            Session::flash('error', __('errors.ehealth.messages.submission_not_tracked'));
        }

        $this->redirectToEpisode($episode->id);
    }

    /**
     * Open the view page of the stored episode.
     *
     * @param  int  $episodeId
     * @return void
     */
    protected function redirectToEpisode(int $episodeId): void
    {
        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.episodes.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'episode' => $episodeId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.episodes.view',
            [legalEntity(), 'person' => $this->personId, 'episode' => $episodeId],
            navigate: true
        );
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
            config('ehealth.episode.legal_entity_episode_types.' . legalEntity()->type->name, [])
        );

        $legalEntityTypes = array_keys($this->episodeTypes);

        $this->employeeEpisodeTypes = collect($this->employees)
            ->mapWithKeys(static fn (array $employee): array => [
                $employee['uuid'] => array_values(array_intersect(
                    $legalEntityTypes,
                    config('ehealth.episode.employee_episode_types.' . $employee['employeeType'], [])
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
            ->whereIn('employee_type', config('ehealth.episode.allowed_episode_care_manager_employee_types', []))
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
