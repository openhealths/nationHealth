<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use App\Rules\InDictionary;
use App\Traits\FormTrait;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class PatientVerifications extends Component
{
    use FormTrait;
    use WithPagination;

    /**
     * Verification streams the list endpoint reports on, mapping the response key to the language one.
     *
     * @var array
     */
    public const array VERIFICATION_STREAMS = [
        'drfo' => 'drfo',
        'dracsDeath' => 'dracs_death',
        'dracsBirth' => 'dracs_birth',
        'dmsPassport' => 'dms_passport',
        'unzr' => 'unzr',
        'nhs' => 'nhs'
    ];

    public string $filterEmployeeId = '';

    public string $filterVerificationStatus = '';

    public string $filterStatus = '';

    /**
     * Narrows the result down to a single verification stream, which eHealth itself cannot filter by.
     *
     * @var string
     */
    public string $filterDracsDeathStatus = '';

    /**
     * Employees of the current legal entity the verifications can be filtered by.
     *
     * @var array
     */
    public array $employees = [];

    public array $dictionaryNames = [
        'LANGUAGE',
        'POSITION',
        'PERSON_STATUSES',
        'PERSON_VERIFICATION_STATUSES'
    ];

    public bool $isSearching = false;

    /**
     * @return void
     */
    public function mount(): void
    {
        $this->getDictionary();

        $this->employees = Employee::whereLegalEntityId(legalEntity()->id)
            ->active()
            ->select(['id', 'uuid', 'party_id', 'position'])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position
            ])
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    /**
     * @return void
     */
    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->resetPage();
        $this->isSearching = true;
    }

    /**
     * @return void
     */
    public function resetFilters(): void
    {
        $this->reset([
            'filterEmployeeId',
            'filterVerificationStatus',
            'filterStatus',
            'filterDracsDeathStatus',
            'isSearching'
        ]);

        $this->resetValidation();
        $this->resetPage();
    }

    /**
     * Name the filter fields the way they are labeled for the user.
     *
     * @return array
     */
    protected function validationAttributes(): array
    {
        return [
            'filterEmployeeId' => __('forms.employee_id'),
            'filterVerificationStatus' => __('patients.verification_status'),
            'filterStatus' => __('forms.status.label'),
            'filterDracsDeathStatus' => __('patient-verifications.dracs_death_status')
        ];
    }

    /**
     * Spell out why a picked employee is rejected instead of the generic wording.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'filterEmployeeId.exists' => __('patient-verifications.errors.employee_not_found')
        ];
    }

    /**
     * Rules of the patient verification search filters.
     *
     * @return array
     */
    protected function filterValidationRules(): array
    {
        return [
            'filterEmployeeId' => [
                'bail',
                'required',
                'uuid',
                Rule::exists('employees', 'uuid')->where('legal_entity_id', legalEntity()->id)
            ],
            'filterVerificationStatus' => ['nullable', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'filterStatus' => ['nullable', new InDictionary('PERSON_STATUSES')],
            'filterDracsDeathStatus' => ['nullable', new InDictionary('PERSON_VERIFICATION_STATUSES')]
        ];
    }

    /**
     * Fetch a single page of the verification statuses of the persons declared to the picked employee.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedVerifications(): LengthAwarePaginator
    {
        $perPage = (int) config('pagination.per_page');
        $page = (int) $this->getPage();

        try {
            [$verifications, $total] = $this->filterDracsDeathStatus === ''
                ? $this->fetchPage($page, $perPage)
                : $this->fetchNarrowedByDracsDeath($page, $perPage);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading person verification statuses');
            $verifications = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($verifications), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    /**
     * Let eHealth do the paging, as it supports every filter the user picked.
     *
     * @param  int  $page
     * @param  int  $perPage
     * @return array  The page of verifications and the total number of entries
     * @throws EHealthException|EHealthConnectionException
     */
    private function fetchPage(int $page, int $perPage): array
    {
        $response = EHealth::person()->getPersonsVerificationStatuses(
            $this->filterEmployeeId,
            $this->searchQuery(['page' => $page, 'page_size' => $perPage])
        );

        return [Arr::toCamelCase($response->validate()), $response->getPaging()['total_entries']];
    }

    /**
     * Narrow the result down to a single verification stream.
     *
     * The endpoint filters by the cumulative verification status only, so every page has to be walked and
     * narrowed here; paging the eHealth result directly would leave the page sizes and the total wrong.
     *
     * @param  int  $page
     * @param  int  $perPage
     * @return array  The page of matching verifications and the total number of matches
     * @throws EHealthException|EHealthConnectionException
     */
    private function fetchNarrowedByDracsDeath(int $page, int $perPage): array
    {
        $matching = [];
        $eHealthPage = 1;

        do {
            $response = EHealth::person()->getPersonsVerificationStatuses(
                $this->filterEmployeeId,
                $this->searchQuery([
                    'page' => $eHealthPage,
                    'page_size' => config('ehealth.api.page_size')
                ])
            );

            foreach (Arr::toCamelCase($response->validate()) as $verification) {
                if ($verification['details']['dracsDeath']['verificationStatus'] === $this->filterDracsDeathStatus) {
                    $matching[] = $verification;
                }
            }

            $eHealthPage++;
        } while ($response->isNotLast());

        return [array_slice($matching, ($page - 1) * $perPage, $perPage), count($matching)];
    }

    /**
     * Build the query the endpoint itself can filter by.
     *
     * @param  array  $paging
     * @return array
     */
    private function searchQuery(array $paging): array
    {
        return array_filter([
            'status' => $this->filterStatus ?: null,
            'verification_status' => $this->filterVerificationStatus ?: null,
            ...$paging
        ]);
    }

    /**
     * The local records of the persons listed on the current page, keyed by their eHealth id.
     *
     * A person eHealth returns a verification status for is not necessarily synchronised locally yet.
     *
     * @return Collection
     */
    #[Computed]
    public function personsByUuid(): Collection
    {
        $uuids = collect($this->paginatedVerifications->items())->pluck('personId');

        return Person::whereIn('uuid', $uuids)
            ->with(['names', 'phones'])
            ->get()
            ->keyBy('uuid');
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('livewire.person.patient-verifications');
    }
}
