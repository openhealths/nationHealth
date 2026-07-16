@use('App\Enums\Status')
@use('App\Enums\User\Role')
@use('App\Enums\JobStatus')
@use('App\Models\Employee\Employee')
@use('App\Models\Employee\EmployeeRequest')

<div>
    <livewire:components.x-message :key="now()->timestamp"/>
    @php
        $currentUser = auth()->user();
        // We cache the hospital ID so as not to call the legalEntity() function 100 times in a loop
        $currentLegalEntityId = legalEntity()->id;

        $isElevated = $currentUser->hasAllowedRole([Role::ADMIN, Role::HR, Role::OWNER, Role::PHARMACY_OWNER]);
        $canDeactivateElevated = $isElevated;

       // Cache access rights with an array.
       // ADMIN/HR/OWNER/PHARMACY_OWNER are elevated even when eHealth scopes omit employee:write/details/deactivate.
       $permissions = [
        'employee_view' => $currentUser->can('employee:details') || $isElevated,
        'employee_write' => $currentUser->can('employee:write') || $isElevated,
        'employee_deactivate' => $currentUser->can('employee:deactivate') || $canDeactivateElevated,
        'employee_admin_hr' => $isElevated,
        'request_view' => $currentUser->can('employee_request:read') || $isElevated,
        'request_write' => $currentUser->can('employee_request:write') || $isElevated,
        'request_delete' => $currentUser->can('employee_request:write') || $isElevated,
    ];

    $statusOptions = [
        Status::APPROVED->value => __('forms.status.active'),
        Status::NEW->value => __('forms.status.new'),
        // Legacy local SIGNED rows (pre keep-NEW); same UI meaning as submitted NEW
        Status::SIGNED->value => __('forms.status.new'),
        Status::STOPPED->value => __('forms.status.stopped'),
        Status::ENTERED_IN_ERROR->value => __('forms.status.entered_in_error'),
        Status::DISMISSED->value => __('forms.status.stopped'),
        Status::REORGANIZED->value => __('forms.reorganized'),
    ];
    @endphp

    <x-header-navigation class="items-start" x-data="{ showFilter: false }">

        <x-slot name="title">
            {{ __('forms.employees') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('create', EmployeeRequest::class)
            <a href="{{ route('employee-request.create', ['legalEntity' => $currentLegalEntityId]) }}"
                class="button-primary flex items-center gap-2">
                @icon('plus', 'w-4 h-4')
                {{ __('forms.new_employee') }}</a>
            @endcan

            @can('sync', Employee::class)
            <button
                wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                type="button"
                class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                {{ $this->isSync ? 'disabled' : '' }}
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
            </button>
            @endcan
        </div>

        <x-slot name="navigation">
            <div class="flex flex-col -my-4">
                <form wire:submit.prevent="applyFilters">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="flex flex-col lg:flex-row items-stretch lg:items-end gap-2 lg:gap-4 w-full">
                            <div class="w-full lg:w-96">
                                <x-forms.form-group>
                                    <x-slot name="label">
                                        <label for="employee_search"
                                               class="text-sm font-medium text-gray-900 dark:text-white block mb-2 flex items-center gap-1">
                                            <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" aria-hidden="true"
                                                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                                <path stroke="currentColor" stroke-linecap="round"
                                                      stroke-linejoin="round"
                                                      stroke-width="2"
                                                      d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z" />
                                            </svg>
                                            <span>{{ __('forms.employee_search') }}</span>
                                        </label>
                                    </x-slot>
                                    <x-slot name="input">
                                        <div class="form-group group w-full">
                                            <input type="text"
                                                   id="employee_search"
                                                   placeholder=" "
                                                   class="input peer"
                                                   wire:model.defer="search"
                                                   autocomplete="off" />
                                            <label for="employee_search" class="label">ПІБ</label>
                                        </div>
                                    </x-slot>
                                </x-forms.form-group>
                            </div>
                            <button type="button"
                                    class="button-minor flex items-center justify-center gap-2 w-full lg:w-auto self-stretch lg:self-auto lg:-translate-y-[9px]"
                                    @click="showFilter = !showFilter">
                                    @icon('adjustments', 'w-4 h-4')
                                <span>{{ __('forms.additional_search_parameters') }}</span>
                            </button>
                        </div>

                        <div x-cloak x-show="showFilter" x-transition class="pt-0 mt-1">
                            <div class="form-row-4">
                                <div class="form-group phone-wrapper">
                                    <input wire:model.defer="filter.phone"
                                           wire:keydown.enter="applyFilters"
                                           type="tel" placeholder=" "
                                           class="peer input pl-10 with-leading-icon text-gray-500"
                                           x-mask="+380999999999" id="filter_phone" />
                                    <label for="filter_phone" class="label pl-10">{{ __('forms.phone') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input wire:model.defer="filter.email" wire:keydown.enter="applyFilters"
                                           name="filter_email" id="filter_email" class="input peer" placeholder=" "
                                           autocomplete="off" />
                                    <label for="filter_email" class="label">Email</label>
                                </div>
                            </div>
                            <div class="form-row-4">
                                <div class="form-group group">
                                    <select wire:model.defer="filter.role" wire:keydown.enter="applyFilters"
                                            id="filter_role"
                                            class="input peer text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        <option value="">Всі ролі</option>
                                        @foreach($dictionaries['EMPLOYEE_TYPE'] ?? [] as $key => $value)
                                            <option value="{{ $key }}">{{ $value }}</option>
                                        @endforeach
                                    </select>
                                    <label for="filter_role" class="label">Роль працівника</label>
                                </div>
                                <div class="form-group group">
                                    <select wire:model.defer="filter.position" wire:keydown.enter="applyFilters"
                                            id="filter_position"
                                            class="input peer text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        <option value="">Всі посади</option>
                                        @foreach($dictionaries['POSITION'] ?? [] as $key => $value)
                                            <option value="{{ $key }}">{{ $value }}</option>
                                        @endforeach
                                    </select>
                                    <label for="filter_position" class="label">{{ __('forms.position') }}</label>
                                </div>
                            </div>
                            <div class="form-row-4">
                                <div class="form-group group">
                                    <select wire:model.defer="filter.division_id" wire:keydown.enter="applyFilters"
                                            name="filter_division"
                                            id="filter_division"
                                            class="input peer text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        <option value="">Всі підрозділи</option>
                                        @foreach($divisions ?? [] as $division)
                                            <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                                        @endforeach
                                    </select>
                                    <label for="filter_division" class="label">Медичний заклад</label>
                                </div>
                                <div class="form-group group">
                                    <x-forms.multiselect
                                        bind="status"
                                        :options="$statusOptions"
                                        label="{{ __('forms.status.label') }}"
                                        placeholder="Оберіть статуси"
                                    />
                                </div>
                            </div>
                            <div class="form-row-4">
                                <div class="form-group group">
                                    <input wire:model.defer="filter.tax_id" wire:keydown.enter="applyFilters"
                                           name="filter_tax_id" id="filter_tax_id" class="input peer" placeholder=" "
                                           autocomplete="off" />
                                    <label for="filter_tax_id" class="label">{{ __('forms.tax_id') }}</label>
                                </div>
                                <div class="form-group group">
                                    <select wire:model.defer="filter.verification_status" wire:keydown.enter="applyFilters"
                                            id="filter_verification_status"
                                            class="input peer text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                                    >
                                        <option value="">{{ __('forms.all_verification_statuses') }}</option>
                                        @foreach(\App\Enums\Party\VerificationStatus::cases() as $verificationStatus)
                                            <option value="{{ $verificationStatus->value }}">{{ $verificationStatus->label() }}</option>
                                        @endforeach
                                    </select>
                                    <label for="filter_verification_status" class="label">{{ __('party_verification.status') }}</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-9 mt-6 flex flex-col sm:flex-row gap-2 w-full">
                            <button type="submit" class="flex items-center gap-2 button-primary">
                                @icon('search', 'w-4 h-4')
                                <span>{{ __('forms.search') }}</span>
                            </button>

                            <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                                {{ __('forms.reset_all_filters') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </x-slot>
    </x-header-navigation>

    <x-section class="shift-content pl-3.5">
        <div class="space-y-6 employee-section-no-left-padding mt-6">
            <div class="table-container-responsive overflow-x-auto" style="max-width:100%;" wire:key="{{ $filterKey }}">
                @forelse($parties as $party)
                    @php
                        // Filter requests: exclude those that are already APPROVED and have an applied_at date.
                        // We use strict filtering on the collection to avoid showing historical processed requests.
                        $drafts = $party->employeeRequests->where('legal_entity_id', $currentLegalEntityId)->reject(function ($request) {
                            $status = $request->status instanceof \UnitEnum ? $request->status->value : $request->status;
                            return $status === Status::APPROVED->value;
                        });

                        $employees = $party->employees->where('legal_entity_id', $currentLegalEntityId);

                        $positions = $drafts->merge($employees)->sortByDesc('updated_at');

                        // Check permissions for actions.
                        // We iterate through the filtered list of positions.
                        $hasAnyActionInTable = $positions->contains(function ($pos) use ($permissions) {
                            $isEmp = $pos instanceof Employee;
                            // Safe access to status value handling both Enum objects and strings
                            $status = $pos->status instanceof \UnitEnum ? $pos->status->value : $pos->status;

                            if ($isEmp) {
                                return $permissions['employee_view'] ||
                                       $permissions['employee_write'] ||
                                       ($status === Status::APPROVED->value && $permissions['employee_deactivate']);
                            }

                            // Request checks
                            $isProcessed = !empty($pos->uuid);
                            return $permissions['request_view'] ||
                                   (!$isProcessed && $permissions['request_write']);
                        });
                    @endphp

                    <fieldset
                        class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]"
                        wire:key="party-{{ $party->id }}">
                        <legend class="legend">{{ $party->fullName }}</legend>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400 mt-1 mb-2">
                            @if($party->tax_id)
                                <span>{{ __('forms.tax_id') }}: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $party->tax_id }}</span></span>
                            @endif
                            @if($party->verification_status)
                                @php
                                    $partyVerification = \App\Enums\Party\VerificationStatus::tryFrom($party->verification_status);
                                @endphp
                                <span>{{ __('party_verification.status') }}:
                                    <span class="font-medium text-gray-700 dark:text-gray-200">
                                        {{ $partyVerification?->label() ?? $party->verification_status }}
                                    </span>
                                </span>
                            @endif
                        </div>

                        <div
                            class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-700 pb-4">

                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500 mt-2"
                                 x-data="{ showEmails_{{ $party->id }}: false }">

                                {{-- Phone --}}
                                @if ($mobilePhone = $party->phones->firstWhere('type', 'MOBILE'))
                                    <span class="flex items-center gap-1.5 min-w-0">
                                        <svg class="w-5 h-5 text-gray-500" aria-hidden="true"
                                             xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                             viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round"
                                                                       stroke-linejoin="round" stroke-width="2"
                                                                       d="M18.427 14.768 17.2 13.542a1.733 1.733 0 0 0-2.45 0l-.613.613a1.732 1.732 0 0 1-2.45 0l-1.838-1.84a1.735 1.735 0 0 1 0-2.452l.612-.613a1.735 1.735 0 0 0 0-2.452L9.237 5.572a1.6 1.6 0 0 0-2.45 0c-3.223 3.2-1.702 6.896 1.519 10.117 3.22 3.221 6.914 4.745 10.12 1.535a1.601 1.601 0 0 0 0-2.456Z" /></svg>
                                        <a href="tel:{{ $mobilePhone->number }}" class="truncate hover:underline"
                                           title="{{ $mobilePhone->number }}">{{ $mobilePhone->number }}</a>
                                    </span>
                                @endif

                                {{-- Email --}}
                                @php
                                    $emailsCollection = $employees
                                        ->map(fn($emp) => $emp->loadMissing('users')->users?->map(fn($user) => $user->email))
                                        ->flatten()
                                        ->filter()
                                        ->unique();

                                    $visibleEmail = $emailsCollection->first();
                                    $hiddenEmails = $emailsCollection->slice(1);
                                    $hiddenCount = $hiddenEmails->count();
                                @endphp

                                @if ($visibleEmail)
                                    <span class="flex items-center gap-1.5 min-w-0 relative">
                                        <svg class="w-6 h-6 text-gray-800 dark:text-white shrink-0" aria-hidden="true"
                                             xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                             viewBox="0 0 24 24">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-width="2"
                                                  d="m3.5 5.5 7.893 6.036a1 1 0 0 0 1.214 0L20.5 5.5M4 19h16a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1Z" />
                                        </svg>
                                        <a href="mailto:{{ $visibleEmail }}" class="hover:underline truncate"
                                           title="{{ $visibleEmail }}">{{ $visibleEmail }}</a>

                                        @if ($hiddenCount > 0)
                                            <button type="button"
                                                    @click.stop="showEmails_{{ $party->id }} = !showEmails_{{ $party->id }}"
                                                    class="text-sm font-semibold text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 cursor-pointer p-0.5 rounded-full"
                                                    title="Показати {{ $hiddenCount }} додаткових email"
                                            >
                                                +{{ $hiddenCount }}
                                            </button>
                                        @endif

                                        @if ($hiddenCount > 0)
                                            <div x-show="showEmails_{{ $party->id }}"
                                                 x-on:click.away="showEmails_{{ $party->id }} = false"
                                                 x-collapse.duration.300ms
                                                 class="flex flex-col gap-y-0.5 absolute bg-white dark:bg-gray-800 z-10 p-2 rounded-md shadow-lg top-full left-0 mt-1 min-w-max border border-gray-200 dark:border-gray-700"
                                                 x-cloak
                                            >
                                                @foreach ($hiddenEmails as $email)
                                                    <a href="mailto:{{ $email }}"
                                                       class="hover:underline text-gray-500 dark:text-gray-400 text-sm">{{ $email }}</a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </span>
                                @endif
                            </div>

                            <div class="flex items-center gap-4">
                                @if($employees->isNotEmpty())
                                    @php
                                        // Find the last active employee of this person to check the rights
                                        $latestEmployee = $employees->first(); // or through the method by which you get a topical position

                                        // Check if ANY of the employee positions for this party is an OWNER
                                        $isOwner = $employees->contains(fn($emp) => $emp->employeeType === Role::OWNER->value);
                                        $hasUserLinked = $latestEmployee && !empty($latestEmployee->userId);

                                        // We check the possibility of editing personal data according to your rules:
                                        // 1. Not the owner 3. Not exempt
                                        $canEditParty = $latestEmployee
                                            && !$isOwner
                                            && $latestEmployee->status !== Status::DISMISSED;
                                    @endphp
                                    @can('create', EmployeeRequest::class)
                                        {{-- Edit personal data button --}}
                                        @if($canEditParty)
                                            <a href="{{ route('party.edit', ['legalEntity' => $currentLegalEntityId, 'party' => $party->id]) }}"
                                               class="cursor-pointer text-blue-600 hover:text-blue-800 flex items-center gap-1">
                                                @icon('file-lines', 'w-4 h-4 text-blue-600 hover:text-blue-800')
                                                <span class="text-sm">{{ __('forms.edit_personal_data') }}</span>
                                            </a>
                                        @endif

                                        {{-- Add position button (if you want to restrict for owners too) --}}

                                        <a href="{{ route('employee-request.position-add', ['legalEntity' => $currentLegalEntityId, 'party' => $party->id]) }}"
                                           class="item-add text-blue-600 hover:text-blue-800 flex items-center gap-1">
                                            <span class="text-xl leading-none">+</span>
                                            <span>{{ __('forms.add_position') }}</span>
                                        </a>
                                    @endif
                                @endcan
                            </div>
                        </div>

                        <div class="flow-root mt-4">
                            <div class="max-w-screen-xl">
                                <table class="table-input w-full table-fixed min-w-[600px] text-sm">
                                    <thead class="thead-input">
                                    <tr>
                                        <th scope="col" class="th-input w-[25%]">{{ __('forms.position') }}</th>
                                        <th scope="col" class="th-input w-[29%]">{{ __('forms.role') }}</th>
                                        <th scope="col" class="th-input w-[15%]">{{ __('forms.division') }}</th>
                                        <th scope="col" class="th-input w-[24%]">{{ __('forms.email') }}</th>
                                        <th scope="col" class="th-input w-[10%]">{{ __('forms.status.label') }}</th>
                                        @if($hasAnyActionInTable)
                                            <th scope="col" class="th-input w-[7%] text-center">
                                                {{ __('forms.actions') }}
                                            </th>
                                        @endif
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($positions as $position)
                                        @php
                                            $positionEmail = null;
                                            if ($position instanceof Employee) {
                                                $positionEmail = $party->loadMissing('users')->users->where(fn($user) => $user->id === $position->user_id)->first()?->email ?? null;
                                            } else if ($position instanceof EmployeeRequest) {
                                                $positionEmail = $position->revision->data['party']['email'] ?? null;
                                            }
                                        @endphp
                                        <tr>
                                            <td class="td-input break-words whitespace-normal align-top">
                                                {{ $dictionaries['POSITION'][$position->position] ?? $position->position }}
                                            </td>
                                            <td class="td-input break-words whitespace-normal align-top">
                                                {{ $dictionaries['EMPLOYEE_TYPE'][$position->employee_type] ?? $position->employee_type }}
                                            </td>
                                            <td class="td-input break-words whitespace-normal align-top">
                                                {{ $position->division->name ?? __('forms.undefined') }}
                                            </td>

                                            <td class="td-input break-words whitespace-normal align-top">
                                                @if($positionEmail)
                                                    <a href="mailto:{{ $positionEmail }}" class="hover:underline"
                                                       title="{{ $positionEmail }}">{{ $positionEmail }}</a>
                                                @else
                                                    {{ __('forms.undefined') }}
                                                @endif
                                            </td>

                                            <td class="td-input break-words whitespace-nowrap align-middle">
                                                @php
                                                    $isEmployee = $position instanceof Employee;
                                                    $employeeStatus = $position->status?->value ?? '';
                                                @endphp

                                                @if($isEmployee)
                                                    @if($employeeStatus === Status::APPROVED->value)
                                                        <span class="badge-green">{{__('forms.status.active')}}</span>
                                                    @elseif($employeeStatus === Status::STOPPED->value || $employeeStatus === Status::DISMISSED->value)
                                                        <span class="badge-red">{{__('forms.status.stopped')}}</span>
                                                    @elseif($employeeStatus === Status::ENTERED_IN_ERROR->value)
                                                        <span class="badge-red">{{__('forms.status.entered_in_error')}}</span>
                                                    @elseif($employeeStatus === Status::REORGANIZED->value)
                                                        <span class="badge-yellow">{{__('forms.status.reorganized')}}</span>
                                                    @endif
                                                @else
                                                    @if($position->isLocalDraft())
                                                        <span class="badge-red">{{__('forms.status.draft')}}</span>
                                                    @elseif($position->isPendingEhealth())
                                                        <span class="badge-yellow">{{__('forms.status.new')}}</span>
                                                    @endif
                                                @endif
                                            </td>
                                            <td class="td-input text-center">
                                                @if($position)
                                                    @include('livewire.employee.parts.actions-dropdown', ['position' => $position])
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </fieldset>
                @empty
                    <x-nothing-found />
                @endforelse
            </div>
        </div>

        <div class="pagination max-w-[1280px]" wire:key="pagination-{{ $filterKey }}">
            {{ $parties->links() }}
        </div>
    </x-section>

    @include('livewire.employee.parts.modals.deactivate-modal')
    @include('livewire.employee.parts.modals.delete-draft-modal')
</div>
