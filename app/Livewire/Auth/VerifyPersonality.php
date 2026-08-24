<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\Status;
use Livewire\Component;
use App\Models\LegalEntity;
use Livewire\WithFileUploads;
use App\Models\Relations\Party;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use App\Events\EhealthUserVerified;
use Illuminate\Support\Facades\Auth;
use App\Enums\Employee\RequestStatus;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Employee\EmployeeRequest;
use App\Classes\Cipher\Api\CipherRequest;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

#[Layout('layouts.guest')]
class VerifyPersonality extends Component
{
    use WithFileUploads;

    #[Validate(['required', 'string'])]
    public string $knedp;

    #[Validate(['required', 'file', 'extensions:dat,pfx,pk8,zs2,jks,p7s'])]
    public ?TemporaryUploadedFile $keyContainerUpload = null;

    #[Validate(['required', 'string'])]
    public string $password;

    public function login(): void
    {
        $this->validate();

        try {
            $response = (new CipherRequest())->getPersonalData($this->knedp, $this->keyContainerUpload, $this->password);
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when loading KEP key', 'Сталася помилка під час завантаження ключа');

            return;
        }

        $ownerFullName = $response?->getOwnerFullName();
        $taxId = $response?->getTaxId();
        [$lastName, $firstName, $secondName] = explode(' ', $ownerFullName);

        /*
         * Search for the Party (person) based on the e-signature data.
         * We no longer check for `whereNull('user_id')` as this column
         * was removed from the 'parties' table during refactoring.
         */
        $party = Party::whereTaxId($taxId)
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower($lastName)])
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($firstName)])
            ->whereRaw('LOWER(TRIM(second_name)) = ?', [mb_strtolower($secondName)])
            ->first();

        if (!$party) {
            Session::flash('error', 'Співпадінь не знайдено, зверніться до адміністратора');

            return;
        }

        $user = Auth::user();

        /*
         * This check (`!$user->partyId`) is crucial for idempotency.
         * It handles scenarios where a user might land on this verification
         * page even after they are already linked to a Party.
         *
         * How can this happen?
         * 1. User verifies successfully, `$user->partyId` is set.
         * 2. They are redirected to the dashboard.
         * 3. They use the browser's "Back" button, which re-loads this page.
         * 4. They (mistakenly) try to submit the form a second time.
         *
         * This `if` block prevents our code from trying to re-link an
         * already-linked user.
         */
        if (!$user->partyId) {
            $user->partyId = $party->id;
            $user->save();
        }

        $legalEntityUuid = Session::pull('selected_legal_entity_uuid');
        $legalEntity = LegalEntity::whereUuid($legalEntityUuid)->firstOrFail();

        // Get all EmployeeRequests for the user's email that are APPROVED and have a start_date, ordered by most recent
        $employeeRequests = EmployeeRequest::where('email', $user->email)
            ->where(
                fn (Builder $query) =>
                $query->where('status', RequestStatus::APPROVED)
                    ->whereNotNull('start_date')
            )
            ->latest('applied_at')
            ->get();

        if ($employeeRequests->isEmpty()) {
            Session::flash('error', 'Для вашого профілю не знайдено записів про посади у цьому закладі. Зверніться до адміністратора.');

            return;
        }

        // Update all employees of the user's party that match the legal entity
        // and employee request criteria, setting their user_id to the current user's id
        $affectedRows = $party->employees()
            ->whereLegalEntityId($legalEntity->id)
            ->where('status', Status::APPROVED)
            ->whereNull('user_id')
            ->where(function (Builder $query) use ($employeeRequests) {
                foreach ($employeeRequests as $request) {
                    $query->orWhere(
                        fn (Builder $q) => $q
                            ->where('employee_type', $request->employee_type)
                            ->where('position', $request->position)
                            ->where('start_date', $request->getRawOriginal('start_date'))
                    );
                }
            })
            ->update(['user_id' => $user->id]);

        if ($affectedRows === 0) {
            // If no employees were updated, it means there are no matching employee records for this user and legal entity
            // Or the user is already linked to the party and has no new employee records to link
            $isAlreadyVerified = $party->employees()
                ->whereLegalEntityId($legalEntity->id)
                ->whereUserId($user->id)
                ->where(function ($query) use ($employeeRequests) {
                    foreach ($employeeRequests as $request) {
                        $query->orWhere(
                            fn ($q) => $q
                                ->where('employee_type', $request->employee_type)
                                ->where('position', $request->position)
                                ->where('start_date', $request->getRawOriginal('start_date'))
                        );
                    }
                })
                ->exists();

            if (!$isAlreadyVerified) {
                Session::flash('error', __('auth.login.error.no_active_positions'));

                return;
            }
        }

        EhealthUserVerified::dispatch($user, $legalEntity->id);

        $this->redirectRoute('dashboard', ['legalEntity' => $legalEntity], navigate: true);
    }
}
