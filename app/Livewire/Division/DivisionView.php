<?php

declare(strict_types=1);

namespace App\Livewire\Division;

use Auth;
use Exception;
use Throwable;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Enums\Division\Status;
use Livewire\Attributes\Locked;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Traits\WorkTimeUtilities;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use App\Traits\Addresses\AddressSearch;
use App\Livewire\Division\Trait\HasAction;
use App\Traits\Addresses\ReceptionAddressSearch;
use Livewire\Features\SupportRedirects\Redirector;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class DivisionView extends DivisionComponent
{
    use WorkTimeUtilities;
    use ReceptionAddressSearch;
    use AddressSearch;
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

        $this->statusLabel = Status::tryFrom($division->status->value)?->label() ?? __('forms.unknown');
        $this->statusStyle = Status::tryFrom($division->status->value)?->cssClass() ?? 'status-alert-default';

        $this->setDictionary();
    }

    /**
     * Set the division form data based on the provided Division model.
     *
     * - Sets the main division parameters from the model.
     * - Assigns the address and phones to the form.
     * - Initializes working hours if not already set.
     *
     * @param  Division  $division
     * @return void
     */
    public function setDivisionData(Division $division)
    {
        $this->divisionForm->setDivision($division->toArray());

        $this->divisionForm->division['addresses'] = $division->addresses->toArray();

        if (!empty($this->divisionForm->division['addresses'])) {
            foreach ($this->divisionForm->division['addresses'] as $address) {
                $addressType = strtolower($address['type']);

                switch ($addressType) {
                    case 'residence':
                        $this->address = $address;
                        break;
                    case 'reception':
                        $this->receptionAddress = $address;
                        $this->divisionForm->showReceptionAddress = true;
                        break;
                    default:
                        continue 2;
                }
            }
        }

        $this->divisionForm->division['phones'] = $division->phones->toArray();

        $this->divisionForm->division['id'] = $division->id ?? '';
        $this->divisionForm->division['uuid'] = $division->uuid ?? '';
    }

    /**
     * Synchronize all the Divisions with stored on the eHealths side
     *
     * @return void
     *
     * @throws Exception|EHealthResponseException|EHealthValidationException
     */
    public function sync(): RedirectResponse|Redirector|null
    {
        if (Auth::user()->cannot('viewAny', Division::class)) {
            Session::flash('error', __('divisions.policy.deny.sync'));

            return null;
        }

        $division = Division::filterByLegalEntityId(legalEntity()->id)->where('uuid', $this->divisionUuid)->first();

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

        Repository::division()->syncDivisionData($divisionData, legalEntity());

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
