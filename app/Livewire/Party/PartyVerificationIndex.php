<?php

declare(strict_types=1);

namespace App\Livewire\Party;

use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\PartyVerificationDetailsUpsert;
use App\Jobs\PartyVerificationSync;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Services\Party\PartyVerificationBulkAccess;
use App\Services\Party\PartyVerificationCache;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\ProcessesPartyVerificationResponses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class PartyVerificationIndex extends Component
{
    use AuthorizesRequests;
    use BatchLegalEntityQueries;
    use ProcessesPartyVerificationResponses;
    use WithPagination;

    public LegalEntity $legalEntity;

    public string $dracsDeathStatus = '';

    public bool $isSyncing = false;

    public bool $canBulkSync = false;

    public bool $canDetailsSync = false;

    public function updatedDracsDeathStatus(): void
    {
        $this->resetPage();
    }

    public function mount(LegalEntity $legalEntity): void
    {
        $this->legalEntity = $legalEntity;
        $this->refreshSyncCapabilities();
    }

    public function sync(): void
    {
        $this->authorize('syncVerification', Party::class);

        if ($this->isSyncing) {
            return;
        }

        $this->refreshSyncCapabilities();

        $user = Auth::user();

        if (!$user || (!$this->canBulkSync && !$this->canDetailsSync)) {
            $this->notifyFlash(__('party_verification.messages.sync_requires_details_or_read'), 'error');

            return;
        }

        $this->isSyncing = true;

        try {
            $token = session()->get(config('ehealth.api.oauth.bearer_token'));

            if (!$token) {
                $this->notifyFlash(__('party_verification.messages.sync_requires_ehealth_session'), 'error');

                return;
            }

            if ($this->canBulkSync) {
                $this->syncViaBulkList($user, $token);

                return;
            }

            $this->syncViaDetails($user, $token);
        } finally {
            $this->isSyncing = false;
        }
    }

    /**
     * Economical bulk path: sync page 1 of getMany now, queue remaining pages.
     */
    private function syncViaBulkList($user, string $token): void
    {
        $this->legalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_PARTY_VERIFICATION);

        try {
            // LE comes from the OAuth token; the list schema rejects legal_entity_id as an additional property.
            $response = EHealth::party()->getMany([], 1);
        } catch (EHealthConnectionException $e) {
            Log::error('Party verification bulk sync failed: no connection.', ['error' => $e->getMessage()]);
            $this->legalEntity->setEntityStatus(JobStatus::FAILED, LegalEntity::ENTITY_PARTY_VERIFICATION);
            $this->notifyFlash(__('errors.ehealth.messages.no_connection'), 'error');

            return;
        } catch (EHealthValidationException|EHealthResponseException $e) {
            Log::error('Party verification bulk sync failed: API error.', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            $this->legalEntity->setEntityStatus(JobStatus::FAILED, LegalEntity::ENTITY_PARTY_VERIFICATION);
            $this->notifyFlash(__('party_verification.messages.sync_failed'), 'error');

            return;
        }

        $this->processPartyVerificationResponse($response, $this->legalEntity);

        foreach ($response->map($response->validate()) as $partyUuid => $item) {
            if (is_string($partyUuid) && is_array($item)) {
                PartyVerificationCache::put($partyUuid, $item);
            }
        }

        PartyVerificationBulkAccess::markSynced($this->legalEntity);

        if ($response->isNotLast()) {
            Bus::batch([
                new PartyVerificationSync(
                    legalEntity: $this->legalEntity,
                    page: 2,
                    standalone: true
                ),
            ])
                ->name('Party Verification Status Sync')
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('sync_entity', LegalEntity::ENTITY_PARTY_VERIFICATION)
                ->onQueue('sync')
                ->dispatch();

            $this->notifyFlash(__('party_verification.messages.sync_page_done'));

            return;
        }

        $this->legalEntity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_PARTY_VERIFICATION);
        $this->notifyFlash(__('party_verification.messages.sync_success'));
    }

    /**
     * Fallback when token has party_verification:details but not :read (e.g. ADMIN).
     * Syncs first N parties via getDetails, queues the rest.
     */
    private function syncViaDetails($user, string $token): void
    {
        $parties = $this->localPartiesQuery()->get();
        $pageSize = max(1, (int) config('ehealth.party_verification.details_sync_page_size', 50));
        $firstPage = $parties->take($pageSize);
        $remaining = $parties->slice($pageSize)->values();

        $this->legalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_PARTY_VERIFICATION);

        foreach ($firstPage as $party) {
            try {
                $response = EHealth::party()->getDetails($party->uuid);
                $this->processPartyVerificationDetail($party->uuid, $response, $this->legalEntity);

                $payload = $response->json();
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

                if (is_array($data)) {
                    PartyVerificationCache::put($party->uuid, $data);
                }
            } catch (Throwable $e) {
                Log::warning('Failed to fetch party verification details during sync', [
                    'party_uuid' => $party->uuid,
                    'error' => $e->getMessage(),
                    'user_id' => Auth::id(),
                ]);
            }
        }

        $startJob = $this->getPartyVerificationDetailsStartJob($this->legalEntity, $remaining);

        if ($startJob instanceof PartyVerificationDetailsUpsert) {
            Bus::batch([$startJob])
                ->name('Party Verification Details Sync')
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('sync_entity', LegalEntity::ENTITY_PARTY_VERIFICATION)
                ->onQueue('sync')
                ->dispatch();

            $this->notifyFlash(__('party_verification.messages.sync_page_done'));

            return;
        }

        $this->legalEntity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_PARTY_VERIFICATION);
        $this->notifyFlash(__('party_verification.messages.sync_success'));
    }

    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        $this->refreshSyncCapabilities();

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
        return $this->localPartiesQuery()
            ->get()
            ->map(function (Party $party) {
                $cached = PartyVerificationCache::get($party->uuid);
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
                        'dms_passport' => ['verification_status' => $status],
                    ],
                ];
            })
            ->values();
    }

    private function localPartiesQuery()
    {
        return Party::query()
            ->whereHas(
                'employees',
                fn ($query) => $query->where('legal_entity_id', $this->legalEntity->id)
            )
            ->whereNotNull('uuid')
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    private function refreshSyncCapabilities(): void
    {
        $scopes = app(TokenStorage::class)->getTokenScopes();
        $this->canBulkSync = PartyVerificationBulkAccess::canBulkSync($scopes);
        $this->canDetailsSync = PartyVerificationBulkAccess::canDetailsSync($scopes);
    }

    /**
     * Livewire requests do not reliably surface session flashes in the layout toast —
     * use the same flashMessage event as other index sync actions.
     */
    private function notifyFlash(string $message, string $type = 'success'): void
    {
        $this->dispatch('flashMessage', [
            'message' => $message,
            'type' => $type,
        ]);
    }
}
