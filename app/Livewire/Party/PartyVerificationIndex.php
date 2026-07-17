<?php

declare(strict_types=1);

namespace App\Livewire\Party;

use App\Classes\eHealth\EHealth;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

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
        if (!Auth::user()?->can('party_verification:details')) {
            abort(403, __('forms.no_actions_available'));
        }
        $this->legalEntity = $legalEntity;
    }

    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        $localItems = $this->localVerificationItems();

        if (!empty($this->dracsDeathStatus)) {
            $localItems = $localItems->filter(
                fn (array $item) => ($item['verification_status'] ?? null) === $this->dracsDeathStatus
            )->values();
        }

        $perPage = 50;
        $total = $localItems->count();
        $pageItems = $localItems
            ->slice(($this->getPage() - 1) * $perPage, $perPage)
            ->values();

        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        $enriched = $this->enrichPageWithDetails($pageItems, $token);

        $paginator = new LengthAwarePaginator(
            $enriched,
            $total,
            $perPage,
            $this->getPage(),
            ['path' => request()->url()]
        );

        return view('livewire.party.party-verification-index', [
            'verifications' => $paginator,
        ]);
    }

    /**
     * Local parties for the current legal entity (list source).
     * Stream statuses are filled later via getDetails (party_verification:details).
     */
    private function localVerificationItems(): Collection
    {
        return Party::query()
            ->whereHas(
                'employees',
                fn ($query) => $query->where('legal_entity_id', $this->legalEntity->id)
            )
            ->whereNotNull('uuid')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Party $party) {
                $status = $party->verification_status ?: '-';

                return [
                    'party_id' => $party->uuid,
                    'party_name' => $party->fullName,
                    'local_id' => $party->id,
                    'verification_status' => $status,
                    'details' => [
                        'drfo' => ['verification_status' => $status],
                        'dracs_death' => ['verification_status' => $status],
                    ],
                ];
            })
            ->values();
    }

    /**
     * Enrich current page via GET /api/parties/{id}/verification (party_verification:details).
     *
     * @param  Collection<int, array<string, mixed>>  $pageItems
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichPageWithDetails(Collection $pageItems, ?string $token): Collection
    {
        if (empty($token) || $pageItems->isEmpty()) {
            return $pageItems;
        }

        return $pageItems->map(function (array $item) use ($token) {
            $uuid = $item['party_id'] ?? null;
            if (!is_string($uuid) || $uuid === '') {
                return $item;
            }

            try {
                $response = EHealth::party()
                    ->withToken($token)
                    ->getDetails($uuid);

                $payload = $response->json();
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
                $fallback = $item['verification_status'] ?? '-';

                $item['verification_status'] = data_get($data, 'verification_status', $fallback) ?: $fallback;
                $item['details'] = [
                    'drfo' => [
                        'verification_status' => data_get($data, 'details.drfo.verification_status', $fallback) ?: $fallback,
                    ],
                    'dracs_death' => [
                        'verification_status' => data_get($data, 'details.dracs_death.verification_status', $fallback) ?: $fallback,
                    ],
                ];

                $remoteStatus = data_get($data, 'verification_status');
                if (is_string($remoteStatus) && $remoteStatus !== '') {
                    Party::query()
                        ->where('uuid', $uuid)
                        ->where('verification_status', '!=', $remoteStatus)
                        ->update(['verification_status' => $remoteStatus]);
                }
            } catch (Throwable $e) {
                Log::warning('Failed to fetch party verification details', [
                    'party_uuid' => $uuid,
                    'error' => $e->getMessage(),
                    'user_id' => Auth::id(),
                ]);
            }

            return $item;
        })->values();
    }
}
