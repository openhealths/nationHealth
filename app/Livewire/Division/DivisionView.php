<?php

declare(strict_types=1);

namespace App\Livewire\Division;

use Auth;
use Exception;
use Throwable;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Enums\Status;
use Livewire\Attributes\Locked;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Dto\Division\Model as DivisionData;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Traits\WorkTimeUtilities;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use App\Livewire\Division\Trait\HasAction;
use Livewire\Features\SupportRedirects\Redirector;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class DivisionView extends DivisionComponent
{
    use WorkTimeUtilities;
    use HasAction;

    #[Locked]
    public string $divisionUuid = '';

    public string $statusLabel = '';

    public string $statusStyle = '';

    public function mount(LegalEntity $legalEntity, Division $division): void
    {
        if (!$division) {
            abort(404);
        }

        $this->setDivisionData($division);

        $this->divisionUuid = $division->uuid ?? '';

        $this->statusLabel = $division->status->label();
        $this->statusStyle = match ($division->status) {
            Status::ACTIVE => 'status-alert-green',
            Status::INACTIVE, Status::DRAFT => 'status-alert-red',
            Status::UNSYNCED => 'status-alert-yellow',
            default => 'status-alert-default',
        };

        $this->setDictionary();
    }

    /**
     * Synchronize all the Divisions with stored on the eHealths side
     *
     * @return void
     * @throws Exception|EHealthResponseException|EHealthValidationException
     */
    public function sync(): RedirectResponse|Redirector|null
    {
        if (Auth::user()->cannot('division:details') || $this->divisionUuid === '') {
            Session::flash('error', __('divisions.policy.deny.sync'));

            return null;
        }

        $division = Division::query()
            ->filterByLegalEntityId(legalEntity()->id)
            ->where('uuid', $this->divisionUuid)
            ->first();

        // For view here should always have a valid division model
        // Only Draft divisions can be synchronized
        if (!$division) {
            Session::flash('error', __('divisions.policy.deny.sync'));

            return null;
        }

        try {
            $response = EHealth::division()->getDetails(uuid: $division->uuid);
            $divisionData = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':createDivision', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return null;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':createDivision', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return null;
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(static::class . ': [syncDivisions]: ', ['error' => $err->getMessage()]);

            session()->flash('error', __('divisions.request.sync.errors.fail'));

            return null;
        }

        try {
            $divisionModelMapped = new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
                ->disableExceptionOnInvalidPropertyPath()
                ->getPropertyAccessor())
                ->map($divisionData, DivisionData::class);

            Repository::division()->saveMappedDivision($divisionModelMapped, $division, legalEntity());
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(self::class . ':sync', ['error' => $err->getMessage()]);
            session()->flash('error', __('errors.database.messages.save_error'));

            return null;
        }

        return redirect()
            ->route('division.view', [legalEntity(), $division->id])
            ->with('success', __('Інформацію успішно оновлено'));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function render()
    {
        return view('livewire.division.division-view');
    }
}
