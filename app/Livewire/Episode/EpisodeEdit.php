<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Throwable;

class EpisodeEdit extends BaseEpisodeComponent
{
    /**
     * Local episode ID.
     *
     * @var int
     */
    #[Locked]
    public int $episodeId;

    /**
     * Whether the episode is still a local draft that has not been sent to eHealth.
     *
     * @var bool
     */
    #[Locked]
    public bool $isDraft = false;

    /**
     * Request-scoped memoized episode model.
     *
     * @var Episode|null
     */
    private ?Episode $episodeModel = null;

    /**
     * Bind the route models and the episode being edited.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  Episode|null  $episode
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Episode $episode = null
    ): void {
        $this->episodeModel = $episode;
        $this->episodeId = $episode->id;
        $this->isDraft = $episode->status === Status::DRAFT;

        parent::mount($legalEntity, $person, $preperson);
    }

    /**
     * Load the select options and fill the form with the stored episode.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        parent::initializeComponent();

        $episode = $this->episode()->load(['type', 'careManager', 'period']);

        $this->form->fill(array_merge(Fhir::episode()->fromFhir($episode->toArray()), ['episodeId' => $episode->id]));
    }

    /**
     * Save the changes: a draft stays local, an episode known to eHealth is updated there first.
     *
     * @return void
     */
    public function save(): void
    {
        $episode = $this->episode();

        if (Auth::user()->cannot('update', $episode)) {
            Session::flash('error', __('episodes.policy.update'));

            return;
        }

        $episode->status === Status::DRAFT ? $this->saveDraft($episode) : $this->updateInEHealth($episode);
    }

    /**
     * Validate the draft, create the episode in eHealth and turn the local draft into an active episode.
     *
     * @return void
     */
    public function sendToEHealth(): void
    {
        $episode = $this->episode();

        if (Auth::user()->cannot('update', $episode)) {
            Session::flash('error', __('episodes.policy.update'));

            return;
        }

        if ($episode->status !== Status::DRAFT) {
            Session::flash('error', __('episodes.messages.not_a_draft'));

            return;
        }

        $validated = $this->validateForm($this->form->rulesForCreate());

        if ($validated === null) {
            return;
        }

        $formattedData = $this->formatEpisode($validated, Status::ACTIVE);

        try {
            EHealth::episode()->create($this->uuid, Arr::toSnakeCase($formattedData));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while creating episode');

            return;
        }

        // eHealth accepted the episode; only now promote the local draft
        try {
            Repository::episode()->updateDraft($episode, $formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store created episode');

            return;
        }

        Session::flash('success', __('episodes.messages.created'));

        $this->redirectToEpisodes();
    }

    /**
     * Delete a draft that has never been sent to eHealth.
     *
     * @return void
     */
    public function deleteDraft(): void
    {
        $episode = $this->episode();

        if (Auth::user()->cannot('delete', $episode)) {
            Session::flash('error', __('episodes.policy.delete'));

            return;
        }

        try {
            Repository::episode()->deleteDraft($episode);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to delete episode draft');

            return;
        }

        Session::flash('success', __('episodes.messages.draft_deleted'));

        $this->redirectToEpisodes();
    }

    /**
     * Keep the changes of a draft locally.
     *
     * @param  Episode  $episode
     * @return void
     */
    protected function saveDraft(Episode $episode): void
    {
        $validated = $this->validateForm($this->form->rulesForCreate());

        if ($validated === null) {
            return;
        }

        try {
            Repository::episode()->updateDraft($episode, $this->formatEpisode($validated, Status::DRAFT));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store episode draft');

            return;
        }

        Session::flash('success', __('episodes.messages.draft_updated'));

        $this->redirectToEpisodes();
    }

    /**
     * Update the fields eHealth allows to change on an existing episode.
     *
     * @param  Episode  $episode
     * @return void
     */
    protected function updateInEHealth(Episode $episode): void
    {
        $validated = $this->validateForm($this->form->rulesForUpdate());

        if ($validated === null) {
            return;
        }

        $formattedData = Fhir::episode()->toUpdateFhir($validated);

        try {
            EHealth::episode()->update($this->uuid, $episode->uuid, Arr::toSnakeCase($formattedData));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while updating episode');

            return;
        }

        // eHealth accepted the changes; only now persist them locally
        try {
            Repository::episode()->update($episode, $formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store updated episode');

            return;
        }

        Session::flash('success', __('episodes.messages.updated'));

        $this->redirectToEpisodes();
    }

    /**
     * Resolve the episode being edited.
     *
     * @return Episode
     */
    protected function episode(): Episode
    {
        return $this->episodeModel ??= Episode::findOrFail($this->episodeId);
    }

    public function render(): View
    {
        return view('livewire.episode.episode-edit');
    }
}
