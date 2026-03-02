<?php

declare(strict_types=1);

namespace App\Livewire\Party;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class PartyVerify extends Component
{
    public Party $party;
    public LegalEntity $legalEntity;

    #[Locked]
    public array $verificationDetails = [];

    public string $verificationStream = 'dracs_death';

    #[Locked]
    public bool $showUpdateModal = false;

    #[Validate('required|string|in:VERIFIED,NOT_VERIFIED')]
    public string $status = '';

    #[Validate('required_if:status,NOT_VERIFIED|string|max:255')]
    public string $reason = '';

    #[Validate('nullable|string|max:1000')]
    public string $comment = '';
    public string $backUrl = '';

    public function mount(LegalEntity $legalEntity, Party $party): void
    {
        $this->legalEntity = $legalEntity;
        $this->party = $party;
        $this->loadVerificationDetails();

        $previous = url()->previous();
        $current = request()->url();

        if ($previous !== $current && str_contains($previous, '/party-verification')) {
            $this->backUrl = $previous;
        } else {
            $this->backUrl = route('party.verification.index', ['legalEntity' => $legalEntity->id]);
        }
    }

    /**
     * Determines if there is any problem that can be solved manually.
     */
    #[Computed]
    public function canUpdateVerification(): bool
    {
        // 1. Getting the current death status
        $deathStatus = data_get($this->verificationDetails, 'details.dracs_death.verification_status');

        // 2. Allow the button ONLY if the status is 'NOT_VERIFIED'
        // All other statuses (VERIFIED, VERIFICATION_NEEDED, etc.) will be false and the button will be gray.
        return $deathStatus === 'NOT_VERIFIED';
    }

    /**
     * Loads and filters verification details for the party from the eHealth API.
     *
     * This method retrieves the party details and strictly filters the verification streams
     * to include only the allowed directions ('drfo' and 'dracs_death') as required by the
     * MIS/PIS UI documentation. It handles variations in the API response structure and
     * updates the $verificationDetails property. In case of an API failure or exception,
     * the details are safely defaulted to an empty array.
     *
     * @return void
     */
    public function loadVerificationDetails(): void
    {
        try {
            //Getting data
            $response = EHealth::party()->getDetails($this->party->uuid);
            $data = is_array($response) ? $response : $response->json();

            // We leave ONLY drfo and dracs_death as required by the documentation
            $allowedStreams = ['drfo', 'dracs_death'];

            if (!empty($data['data']['details']) && is_array($data['data']['details'])) {
                $data['data']['details'] = array_filter(
                    $data['data']['details'],
                    static fn ($key) => in_array($key, $allowedStreams, true),
                    ARRAY_FILTER_USE_KEY
                );
            } elseif (!empty($data['details']) && is_array($data['details'])) {
                $data['details'] = array_filter(
                    $data['details'],
                    static fn ($key) => in_array($key, $allowedStreams, true),
                    ARRAY_FILTER_USE_KEY
                );
            }

            $this->verificationDetails = $data['data'] ?? $data;

        } catch (\Throwable $e) {
            $this->verificationDetails = [];
        }
    }

    public function checkAndOpenModal(): void
    {
        if ($this->canUpdateVerification) {
            $this->showUpdateModal = true;
        } else {
            $message = __('party_verification.update_unavailable_reason')
                ?? 'Оновлення даних наразі неможливе, оскільки статус не потребує верифікації.';

            $this->dispatch('flashMessage', [
                'message' => $message,
                'type' => 'error'
            ]);
        }
    }

    public function closeUpdateModal(): void
    {
        $this->showUpdateModal = false;
        $this->reset(['status', 'reason', 'comment']);
        $this->resetErrorBag();
    }

    public function updateStatus(): void
    {
        $this->validate([
            'stream' => 'required|string',
            'status' => 'required|string',
            'reason' => 'required|string',
            'comment' => 'nullable|string|max:3000',
        ]);

        try {
            $data = [
                'verification_status' => $this->status,
                'verification_reason' => $this->reason,
                'verification_comment' => $this->comment,
            ];

            // Wrap the data in the stream key
            $payload = [
                $this->verificationStream => [
                    'status' => $this->status,
                    'reason' => $this->reason,
                    'comment' => $this->comment,
                ]
            ];

            EHealth::party()->update($this->party->uuid, $payload);

            $this->loadVerificationDetails();
            $this->closeUpdateModal();

            $this->dispatch('flashMessage', [
                'message' => __('forms.data_saved_successfully'),
                'type' => 'success'
            ]);

        } catch (EHealthResponseException|EHealthValidationException $e) {
            // We log the details for the developer, but do not display them to the user
            Log::error('[PARTY UPDATE ERROR]', [
                'party_uuid' => $this->party->uuid,
                'message' => $e->getMessage(),
                'details' => method_exists($e, 'getDetails') ? $e->getDetails() : [],
            ]);

            // Displaying a general understandable message
            // Or you can use $e->getFormattedMessage() if you have it well configured
            $this->dispatch('flashMessage', [
                'message' => 'Помилка оновлення в ЕСОЗ: ' . $e->getMessage(),
                'type' => 'error',
                'persistent' => true
            ]);

            $this->dispatch('status-updated-close-modal');

        } catch (\Throwable $e) {
            Log::error('[PARTY UPDATE SYSTEM ERROR]', [
                'party_uuid' => $this->party->uuid,
                'message' => $e->getMessage()
            ]);

            $this->dispatch('flashMessage', [
                'message' => __('errors.generic_error') ?? 'Виникла технічна помилка.',
                'type' => 'error'
            ]);
            $this->dispatch('status-updated-close-modal');
        }
    }

    public function render()
    {
        return view('livewire.party.party-verify');
    }
}
