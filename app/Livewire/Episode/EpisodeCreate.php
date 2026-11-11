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
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

class EpisodeCreate extends BaseEpisodeComponent
{
    /**
     * Load the dictionaries and select options the form depends on, and start the episode now by default.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        parent::initializeComponent();

        $this->form->id = Uuid::uuid1()->toString();
        $this->form->startDate = now()->format(config('app.date_format'));
        $this->form->startTime = now()->format('H:i');
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
            $resolver = app(EHealthJobResolver::class);
            $resolver->assertSuccessful($resolver->resolve($response->validate()));
            // A processed job confirms acceptance, while the patient-scoped detail
            // supplies the authoritative episode persisted by this operation.
            $confirmedEpisode = EHealth::episode()->getById($this->uuid, $formattedData['id'])->validate();
            if ($confirmedEpisode['uuid'] !== $formattedData['id']
                || $confirmedEpisode['status'] !== Status::ACTIVE->value
                || data_get($confirmedEpisode, 'managing_organization.identifier.value') !== data_get($formattedData, 'managingOrganization.identifier.value')
                || data_get($confirmedEpisode, 'care_manager.identifier.value') !== data_get($formattedData, 'careManager.identifier.value')) {
                throw ValidationException::withMessages(['episode' => __('errors.ehealth.messages.response_contract_invalid')]);
            }
        } catch (ValidationException $exception) {
            Session::flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while creating episode');

            return;
        }

        // eHealth accepted the episode; only now persist it locally
        try {
            Repository::episode()->syncFull($this->patient(), [$confirmedEpisode]);
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
