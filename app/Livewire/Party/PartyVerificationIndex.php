<?php

declare(strict_types=1);

namespace App\Livewire\Party;

use App\Classes\eHealth\EHealth;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Traits\ProcessesPartyVerificationResponses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class PartyVerificationIndex extends Component
{
    use AuthorizesRequests;
    use ProcessesPartyVerificationResponses;
    use WithPagination;

    private const string DETAILS_CACHE_PREFIX = 'party_verification_details:';

    private const int DETAILS_CACHE_TTL_SECONDS = 86400;

    public LegalEntity $legalEntity;

    public string $dracsDeathStatus = '';

    public bool $isSyncing = false;

    public function updatedDracsDeathStatus(): void
    {
        $this->resetPage();
    }

    public function mount(LegalEntity $legalEntity): void
    {
        $this->legalEntity = $legalEntity;
    }

    public function sync(): void
    {
        $this->authorize('syncVerification', Party::class);

        if ($this->isSyncing) {
            return;
        }

        $this->isSyncing = true;

        try {
            $parties = Party::query()
                ->whereHas(
                    'employees',
                    fn ($query) => $query->where('legal_entity_id', $this->legalEntity->id)
                )
                ->whereNotNull('uuid')
                ->orderBy('id')
                ->get();

            foreach ($parties as $party) {
                try {
                    $response = EHealth::party()->getDetails($party->uuid);

                    $this->processPartyVerificationDetail($party->uuid, $response, $this->legalEntity);
                    $this->cacheVerificationDetails($party->uuid, $response);
                } catch (Throwable $e) {
                    Log::warning('Failed to fetch party verification details during sync', [
                        'party_uuid' => $party->uuid,
                        'error' => $e->getMessage(),
                        'user_id' => Auth::id(),
                    ]);
                }
            }

            session()->flash('success', __('party_verification.messages.sync_success'));
        } finally {
            $this->isSyncing = false;
        }
    }

    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        $localItems = $this->localVerificationItems();

        if (!empty($this->dracsDeathStatus)) {
            $localItems = $localItems->filter(
                fn (array $item) => ($item['details']['dracs_death']['verification_status'] ?? null) === $this->dracsDeathStatus
            )->values();
        }

        $perPage = 50;
        $total = $localItems->count();
        $pageItems = $localItems
            ->slice(($this->getPage() - 1) * $perPage, $perPage)
            ->values();

        $paginator = new LengthAwarePaginator(
            $pageItems,
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
     * Stream statuses come from cache after manual sync, otherwise from local verification_status.
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
                $cached = Cache::get(self::DETAILS_CACHE_PREFIX . $party->uuid);
                if (is_array($cached)) {
                    return [
                        'party_id' => $party->uuid,
                        'party_name' => $party->fullName,
                        'local_id' => $party->id,
                        'verification_status' => $cached['verification_status'],
                        'details' => $cached['details'],
                    ];
                }

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

    private function cacheVerificationDetails(string $partyUuid, mixed $response): void
    {
        $payload = $response->json();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        Cache::put(
            self::DETAILS_CACHE_PREFIX . $partyUuid,
            [
                'verification_status' => data_get($data, 'verification_status'),
                'details' => [
                    'drfo' => [
                        'verification_status' => data_get($data, 'details.drfo.verification_status'),
                    ],
                    'dracs_death' => [
                        'verification_status' => data_get($data, 'details.dracs_death.verification_status'),
                    ],
                ],
            ],
            self::DETAILS_CACHE_TTL_SECONDS
        );
    }
}
