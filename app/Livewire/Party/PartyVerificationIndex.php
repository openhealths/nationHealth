<?php

declare(strict_types=1);

namespace App\Livewire\Party;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class PartyVerificationIndex extends Component
{
    use WithPagination;

    public LegalEntity $legalEntity;

    public string $dracsDeathStatus = '';

    public function updatedDracsDeathStatus(): void
    {
        $this->resetPage();
    }

    public function mount(LegalEntity $legalEntity): void
    {
        if (!ehealthCanAccessPartyVerification()) {
            abort(403, __('forms.no_actions_available'));
        }
        $this->legalEntity = $legalEntity;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws EHealthConnectionException
     */
    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));

        // We do not pass dracs_death_verification_status to the eHealth API as it is not supported
        // and results in a 422 validation error. Instead, we perform the filtering locally.
        // We always fetch the first page from the API (with default page size 300) to get all entries,
        // then slice the collection locally.
        $items = [];
        try {
            $apiResponse = EHealth::party()
                ->withToken($token)
                ->getMany([], 1);

            $apiData = $apiResponse->json();
            $items = $apiData['data'] ?? [];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to fetch party verifications: ' . $e->getMessage());
        }

        $partyUuids = collect($items)->pluck('party_id')->unique()->toArray();

        $localPartiesObjects = Party::whereIn('uuid', $partyUuids)
            ->get()
            ->keyBy('uuid');

        $mergedItems = collect($items)->map(function ($item) use ($localPartiesObjects) {
            $uuid = $item['party_id'];
            $localParty = $localPartiesObjects->get($uuid);

            //If it is not found locally, skip (or show it as it is, depends on your logic)
            if (!$localParty) {
                return null;
            }

            $item['party_name'] = $localParty->fullName;
            $item['local_id'] = $localParty->id;

            return $item;
        })->filter()->values();

        if (!empty($this->dracsDeathStatus)) {
            $mergedItems = $mergedItems->filter(function ($item) {
                return data_get($item, 'details.dracs_death.verification_status') === $this->dracsDeathStatus;
            })->values();
        }

        $perPage = 50;

        $total = $mergedItems->count();

        $currentPageItems = $mergedItems->slice(($this->getPage() - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $currentPageItems,
            $total,
            $perPage,
            $this->getPage(),
            ['path' => request()->url()]
        );

        return view('livewire.party.party-verification-index', [
            'verifications' => $paginator,
        ]);
    }
}
