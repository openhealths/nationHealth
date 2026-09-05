<?php
declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Throwable;
use Exception;
use Livewire\Component;
use App\Models\Connection;
use App\Models\LegalEntity;
use Livewire\Attributes\Title;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class LegalEntityConnectionIndex extends Component
{
    public bool $showSignatureModal = false;
    public array $form = [];
    public $legalEntity;

    public function mount(?LegalEntity $legalEntity = null)
    {
        $this->legalEntity = $legalEntity ?? request()->route('legalEntity');
    }

    public function sign()
    {
        $this->showSignatureModal = false;

        session()->flash('success', 'Зв\'язок успішно встановлений!');
        return redirect()->route('legal-entity-connection.show', [
            'legalEntity' => $this->legalEntity ?? 1,
            'id' => 'conn-13-1312qe11'
        ]);
    }

    #[Computed]
    public function connections(): LengthAwarePaginator
    {
        $connections = Connection::with(['legalEntity', 'client'])
            ->where('legal_entity_id', $this->legalEntity->id)
            ->get();

        // Pagination
        $perPage = config('pagination.per_page');
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $connections->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $connections->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    /**
     * Synchronize all the Connections with stored ones on the eHealths side
     *
     * @return void
     *
     * @throws Exception|EHealthResponseException|EHealthValidationException
     */
    public function sync(): void
    {
        if (Auth::user()->cannot('sync', Connection::class)) {
            Session::flash('error', __('legal-entity.policy.deny.sync'));

            return;
        }

        $syncQuery = [
            'page' => 1,
            'per_page' => config('ehealth.api.max_per_page')
        ];

        try {
            $response = EHealth::division()->getMany(query: $syncQuery);

            $divisions = $response->validate();

            Repository::division()->saveDivisionsList($divisions);
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':createDivision', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':createDivision', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(static::class . ': [syncDivisions]: ', ['error' => $err->getMessage()]);

            session()->flash('error', __('divisions.request.sync.errors.fail'));

            return;
        }


        session()->flash('success', __('Інформацію успішно оновлено'));
    }

    #[Title('Зв\'язки МІС та СГуСОЗ')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-index', [
            'connections' => $this->connections(),
        ]);
    }
}
