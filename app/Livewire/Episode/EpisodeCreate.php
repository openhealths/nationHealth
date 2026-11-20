<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Enums\Episode\Status;
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
     * Load the dictionaries and select options the form depends on, and start the episode now by default.
     * The only available care manager is selected, while among several the user chooses, as the choice limits the episode types.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        parent::initializeComponent();

        $this->form->id = Str::uuid()->toString();
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

        $validated = $this->validateForm($this->form->rulesForCreate());

        if ($validated === null) {
            return;
        }

        try {
            Repository::episode()->store($this->formatEpisode($validated, Status::DRAFT), $this->patient());
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

        $validated = $this->validateForm($this->form->rulesForCreate());

        if ($validated === null) {
            return;
        }

        try {
            $episode = Repository::episode()->store($this->formatEpisode($validated, Status::DRAFT), $this->patient());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store episode draft');

            return;
        }

        $this->submitToEHealth($episode, $this->formatEpisode($validated, Status::ACTIVE));
    }

    public function render(): View
    {
        return view('livewire.episode.episode-create');
    }
}
