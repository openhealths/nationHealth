<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Dto\Episode\Model as EpisodeData;
use App\Models\MedicalEvents\Sql\Episode;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Throwable;

class EpisodeCreate extends BaseEpisodeComponent
{
    /**
     * Load the dictionaries and select options the form depends on, set the current legal entity as the managing
     * organization and start the episode now by default.
     * The only available care manager is selected, while among several the user chooses, as the choice limits the episode types.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        parent::initializeComponent();

        $this->form->uuid = Str::uuid()->toString();
        $this->form->managingOrganizationId = legalEntity()->uuid;
        $this->form->startDate = now()->format(config('app.date_format'));
        $this->form->startTime = now()->format('H:i');

        if (count($this->employees) === 1) {
            $this->form->careManagerId = $this->employees[0]['uuid'];
        }
    }

    /**
     * Validate the form and keep the episode as a local draft.
     *
     * @return void
     */
    public function createLocally(): void
    {
        if (Auth::user()->cannot('create', Episode::class)) {
            Session::flash('error', __('episodes.policy.create'));

            return;
        }

        if ($this->validateForm($this->form->rulesForCreate()) === null) {
            return;
        }

        try {
            $episodeData = objectMapper()->map($this->form, EpisodeData::class);

            Repository::episode()->store($episodeData, $this->patient());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store episode draft');

            return;
        }

        Session::flash('success', __('episodes.messages.draft_created'));

        $this->redirectToEpisodes();
    }

    /**
     * Validate the form, store the episode as a draft and submit it to eHealth.
     *
     * @return void
     */
    public function create(): void
    {
        if (Auth::user()->cannot('create', Episode::class)) {
            Session::flash('error', __('episodes.policy.create'));

            return;
        }

        if ($this->validateForm($this->form->rulesForCreate()) === null) {
            return;
        }

        try {
            $episodeData = objectMapper()->map($this->form, EpisodeData::class);

            $episode = Repository::episode()->store($episodeData, $this->patient());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store episode draft');

            return;
        }

        $this->submitToEHealth($episode);
    }

    public function render(): View
    {
        return view('livewire.episode.episode-create');
    }
}
