<?php

declare(strict_types=1);

namespace App\Livewire\License;

use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Models\License;
use App\Traits\FormTrait;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class LicenseIndex extends Component
{
    use FormTrait;
    use WithPagination;

    public function mount(LegalEntity $legalEntity): void
    {
    }

    #[Computed]
    public function licenses(): LengthAwarePaginator
    {
        return License::whereLegalEntityId(legalEntity()->id)
            ->orderByDesc('is_primary')
            ->paginate(config('pagination.per_page'));
    }

    /**
     * Synchronize licenses with eHealth, the method overrides existing licenses if uuid is the same
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::license()->getMany();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting licenses');

            return;
        }

        $licences = $this->normalizeDate($response->validate());

        try {
            License::upsert($response->map($licences), uniqueBy: ['uuid'], update: new License()->getFillable());
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing licenses with eHealth: ');

            return;
        }

        legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_LICENSE);

        Session::flash('success', __('licenses.success.sync'));
    }

    public function render(): View
    {
        return view('livewire.license.license-index', ['licenses' => $this->licenses]);
    }
}
