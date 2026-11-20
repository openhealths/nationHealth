<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Classes\eHealth\EHealth;
use App\Dto\Episode\EhealthUpdate as EhealthUpdateData;
use App\Dto\Episode\Form as FormData;
use App\Dto\Episode\Model as EpisodeData;
use App\Dto\Episode\ModelUpdate as EpisodeUpdateData;
use App\Enums\Episode\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
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
     * Whether the draft was opened along with an encounter draft, so it is sent together with that encounter.
     *
     * @var bool
     */
    #[Locked]
    public bool $isSentWithEncounter = false;

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
        $this->isSentWithEncounter = $episode->encounterId !== null;

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

        $episode = $this->episode()->load(['type', 'managingOrganization', 'careManager', 'period']);

        $this->form->fill(objectMapper()->map($episode, FormData::class)->toArray());
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
     * Validate the draft, keep its changes and submit it to eHealth.
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

        if ($episode->encounterId !== null) {
            Session::flash('error', __('episodes.messages.sent_with_encounter'));

            return;
        }

        if ($this->validateForm($this->form->rulesForCreate()) === null) {
            return;
        }

        try {
            $episodeData = objectMapper()->map($this->form, EpisodeData::class);

            Repository::episode()->updateDraft($episode, $episodeData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store episode draft');

            return;
        }

        $this->submitToEHealth($episode);
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
        if ($this->validateForm($this->form->rulesForCreate()) === null) {
            return;
        }

        try {
            $episodeData = objectMapper()->map($this->form, EpisodeData::class);

            Repository::episode()->updateDraft($episode, $episodeData);
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
        if ($this->validateForm($this->form->rulesForUpdate()) === null) {
            return;
        }

        // Both are built from the validated fields before the request, so the local update cannot fail on mapping after the request succeeds
        $episodeData = objectMapper()->map($this->form, EpisodeUpdateData::class);
        $requestData = objectMapper()->map($this->form, EhealthUpdateData::class)->toArray();

        try {
            EHealth::episode()->update($this->uuid, $episode->uuid, $requestData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while updating episode');

            return;
        }

        // eHealth accepted the changes; only now persist them locally
        try {
            Repository::episode()->update($episode, $episodeData);
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
