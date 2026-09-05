<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
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
     * Load the dictionaries and select options the form depends on.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        parent::initializeComponent();

        $this->form->id = Str::uuid()->toString();
    }

    /**
     * Validate the form, create the episode in eHealth and persist it locally.
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

        $formattedData = $this->formatEpisode($validated, Status::ACTIVE);

        try {
            $response = EHealth::episode()->create($this->uuid, Arr::toSnakeCase($formattedData));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while creating episode');

            return;
        }

        logger()->debug('Job ID to further debug', $response->getData());
        // eHealth accepted the episode; only now persist it locally
        try {
            Repository::episode()->store($formattedData, $this->patient());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store created episode');

            return;
        }

        Session::flash('success', __('episodes.messages.created'));

        $this->redirectToEpisodes();
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

    public function render(): View
    {
        return view('livewire.episode.episode-create');
    }
}
