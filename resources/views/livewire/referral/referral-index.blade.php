<div>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-forms.loading />

    <x-header-navigation class="items-start">
        <x-slot name="title">{{ __('referrals.title') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            <button
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap !bg-green-700 !text-white hover:!bg-green-800"
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>

        <x-slot name="navigation">
            <div class="-my-4 flex flex-col">
                <form wire:submit.prevent="search">
                    <div class="flex items-center gap-2 mb-4 text-sm font-medium text-gray-900 dark:text-white">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('referrals.search') }}</span>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group group">
                            <input
                                type="text"
                                id="requisition"
                                placeholder=" "
                                class="input peer"
                                wire:model.defer="requisition"
                                x-data
                                x-on:input="
                                    $event.target.value = $event.target.value
                                        .replace(/[^A-Za-z0-9]/g, '')
                                        .replace(/(.{4})(?! $)/g, '$1-')
                                        .toUpperCase()
                                        .slice(0, 19)
                                "
                                autocomplete="off"
                            />
                            <label for="requisition" class="label">{{ __('referrals.requisition') }}</label>
                        </div>

                        <div class="form-group group">
                            <input
                                type="text"
                                id="patient"
                                placeholder=" "
                                class="input peer"
                                wire:model.defer="patient"
                                autocomplete="off"
                            />
                            <label for="patient" class="label">{{ __('forms.patient') }}</label>
                        </div>
                    </div>

                    <div class="form-row-3 mt-4">
                        @php
                            $statusOptions = [
                                'active' => __('referrals.statuses.active'),
                                'completed' => __('referrals.statuses.completed'),
                                'entered_in_error' => __('referrals.statuses.entered_in_error'),
                                'revoked' => __('referrals.statuses.revoked'),
                                'draft' => __('forms.status.drafts'),
                                'in_progress' => __('referrals.statuses.in_progress'),
                            ];
                        @endphp
                        <div class="form-group group relative" style="z-index: 30;">
                            <x-forms.multiselect
                                bind="status"
                                :initial="$status"
                                :options="$statusOptions"
                                label="{{ __('referrals.show') }}"
                                placeholder="{{ __('referrals.placeholder_status') }}"
                            />
                        </div>
                    </div>

                    <div class="mb-9 mt-6 flex flex-col sm:flex-row gap-2 w-full">
                        <button
                            type="submit"
                            class="button-primary flex items-center justify-center gap-2 self-stretch whitespace-nowrap sm:self-auto"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="resetFilters"
                            class="button-primary-outline-red flex items-center justify-center gap-2 self-stretch whitespace-nowrap sm:self-auto"
                        >
                            {{ __('referrals.btn_reset') }}
                        </button>
                    </div>
                </form>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="shift-content mt-8 flow-root pl-3.5">
        <div class="max-w-screen-xl">
            <!-- Error Message -->
            @if ($errorMessage)
                <div
                    class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400"
                    role="alert"
                >
                    {{ $errorMessage }}
                </div>
            @endif

            @php
                $statusesTranslations = [
                    'active' => __('referrals.status_labels.active'),
                    'completed' => __('referrals.status_labels.completed'),
                    'entered_in_error' => __('referrals.status_labels.entered_in_error'),
                    'entered-in-error' => __('referrals.status_labels.entered_in_error'),
                    'draft' => __('forms.draft'),
                    'revoked' => __('referrals.status_labels.revoked'),
                    'recalled' => __('referrals.status_labels.revoked'),
                    'new' => __('referrals.status_labels.new'),
                    'in_progress' => __('referrals.status_labels.in_progress'),
                    'in_queue' => __('referrals.status_labels.in_queue'),
                ];
            @endphp

            @if ($hasSearched && empty($errorMessage) && !empty($searchResults))
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                            <tr>
                                <th class="index-table-th w-[10%]">{{ __('referrals.table.created_at') }}</th>
                                <th class="index-table-th w-[15%]">{{ __('referrals.table.service') }}</th>
                                <th class="index-table-th w-[15%]">{!! __('referrals.table.patient_name_age') !!}</th>
                                <th class="index-table-th w-[10%]">{{ __('referrals.table.priority') }}</th>
                                <th class="index-table-th w-[10%]">{{ __('referrals.table.program') }}</th>
                                <th class="index-table-th w-[10%]">{{ mb_strtoupper(__('forms.status.label'), 'UTF-8') }}</th>
                                <th class="index-table-th w-[10%]">{{ __('referrals.table.state') }}</th>
                                <th class="index-table-th w-[15%]">{{ __('referrals.table.number') }}</th>
                                <th class="index-table-th w-[5%] text-center">{{ mb_strtoupper(__('forms.action'), 'UTF-8') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($searchResults as $referral)
                                <tr class="index-table-tr" wire:key="referral-{{ $referral['id'] ?? $loop->index }}">
                                    <td class="index-table-td">
                                        @php
                                            $authoredOn = isset($referral['authored_on']) ? \Carbon\Carbon::parse($referral['authored_on']) : null;
                                        @endphp
                                        @if($authoredOn)
                                            <div>{{ $authoredOn->format('d.m.Y') }}</div>
                                            <div class="text-gray-500">{{ $authoredOn->format('H:i') }}</div>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="index-table-td-primary whitespace-normal break-words">
                                        @php
                                            $code = $referral['code']['coding'][0]['code'] ?? '';
                                            $display = $referral['code']['coding'][0]['display'] ?? $code;
                                        @endphp
                                        {{ $display }}
                                    </td>
                                    <td class="index-table-td whitespace-normal break-words">
                                        @php
                                            $patientDisplay = $referral['subject']['display'] ?? __('forms.status.unknown');
                                        @endphp
                                        {{ $patientDisplay }}
                                    </td>
                                    <td class="index-table-td">
                                        {{ ($referral['priority'] ?? '') === 'stat' ? __('referrals.priorities.stat') : (($referral['priority'] ?? '') === 'routine' ? __('referrals.priorities.routine') : ($referral['priority'] ?? '-')) }}
                                    </td>
                                    <td class="index-table-td">
                                        @php
                                            $programDisplay = $referral['program']['display'] ?? '-';
                                        @endphp
                                        {{ $programDisplay }}
                                    </td>
                                    <td class="index-table-td">
                                        @php
                                            $status = $referral['status'] ?? '';
                                            $statusLabel = $statusesTranslations[$status] ?? $status;
                                            $badgeClass = in_array($status, ['entered_in_error', 'entered-in-error'])
                                                ? '!me-0 inline-block w-min whitespace-normal text-center leading-tight'
                                                : '!me-0 inline-block whitespace-nowrap text-center leading-tight';
                                        @endphp
                                        @if(in_array($status, ['active', 'new']))
                                            <span class="badge-green {{ $badgeClass }}">{{ $statusLabel }}</span>
                                        @elseif(in_array($status, ['completed']))
                                            <span class="badge-blue {{ $badgeClass }}">{{ $statusLabel }}</span>
                                        @elseif(in_array($status, ['entered_in_error', 'entered-in-error', 'revoked', 'recalled']))
                                            <span class="badge-red {{ $badgeClass }}">{{ $statusLabel }}</span>
                                        @elseif(in_array($status, ['draft', 'in_progress', 'in_queue']))
                                            <span class="badge-yellow {{ $badgeClass }}">{{ $statusLabel }}</span>
                                        @else
                                            <span class="badge-gray {{ $badgeClass }}">{{ $statusLabel }}</span>
                                        @endif
                                    </td>
                                    <td class="index-table-td">
                                        @php
                                            $programStatus = $referral['program_processing_status'] ?? 'new';
                                            $programStatusLabel = $statusesTranslations[$programStatus] ?? $programStatus;
                                            $programBadgeClass = in_array($programStatus, ['entered_in_error', 'entered-in-error'])
                                                ? '!me-0 inline-block w-min whitespace-normal text-center leading-tight'
                                                : '!me-0 inline-block whitespace-nowrap text-center leading-tight';
                                        @endphp
                                        @if(in_array($programStatus, ['active', 'new']))
                                            <span class="badge-green {{ $programBadgeClass }}">{{ $programStatusLabel }}</span>
                                        @elseif(in_array($programStatus, ['completed']))
                                            <span class="badge-blue {{ $programBadgeClass }}">{{ $programStatusLabel }}</span>
                                        @elseif(in_array($programStatus, ['entered_in_error', 'entered-in-error', 'revoked', 'recalled']))
                                            <span class="badge-red {{ $programBadgeClass }}">{{ $programStatusLabel }}</span>
                                        @elseif(in_array($programStatus, ['draft', 'in_progress', 'in_queue']))
                                            <span class="badge-yellow {{ $programBadgeClass }}">{{ $programStatusLabel }}</span>
                                        @else
                                            <span class="badge-gray {{ $programBadgeClass }}">{{ $programStatusLabel }}</span>
                                        @endif
                                    </td>
                                    <td class="index-table-td">
                                        {{ $referral['id'] ?? '-' }}
                                    </td>
                                    <td class="index-table-td-actions text-center align-middle whitespace-nowrap">
                                        <div class="flex justify-center relative">
                                            <div x-data="{
                                                 open: false,
                                                 toggle() {
                                                     if (this.open) {
                                                         return this.close();
                                                     }
                                                     this.$refs.button.focus();

                                                     this.open = true;
                                                 },
                                                 close(focusAfter) {
                                                     if (!this.open) return;

                                                     this.open = false;

                                                     focusAfter && focusAfter.focus()
                                                 }
                                            }"
                                                 @keydown.escape.prevent.stop="close($refs.button)"
                                                 @focusin.window="!$refs.panel.contains($event.target) && close()"
                                                 x-id="['dropdown-button']"
                                                 class="relative"
                                            >
                                                <button @click="toggle()"
                                                        x-ref="button"
                                                        :aria-expanded="open"
                                                        :aria-controls="$id('dropdown-button')"
                                                        type="button"
                                                        class="hover:text-primary cursor-pointer"
                                                        outline="none"
                                                        id="menu-{{ $referral['id'] ?? $loop->index }}"
                                                >
                                                    <svg class="svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300"
                                                         aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="18"
                                                         height="18" fill="none" viewBox="0 0 24 24">
                                                        <path stroke="currentColor" stroke-linecap="round"
                                                              stroke-linejoin="round"
                                                              stroke-width="2"
                                                              d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                                    </svg>
                                                </button>

                                                <div
                                                    x-show="open"
                                                    x-cloak
                                                    x-ref="panel"
                                                    x-transition.origin.top.left
                                                    @click.outside="close($refs.button)"
                                                    :id="$id('dropdown-button')"
                                                    class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50 divide-y divide-gray-100 dark:divide-gray-600"
                                                >
                                                    @if(($referral['status'] ?? '') === 'draft')
                                                        <div class="py-1">
                                                            <a href="#" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                                @icon('file-plus', 'w-5 h-5 text-gray-600 dark:text-gray-300') 
                                                                {{ __('referrals.actions.create_en') }}
                                                            </a>
                                                            <a href="#" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                                @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300') 
                                                                {{ __('forms.edit') }}
                                                            </a>
                                                        </div>
                                                        <div class="py-1">
                                                            <a href="#" wire:click.prevent="openCancelModal('{{ $referral['id'] }}')" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600">
                                                                @icon('trash', 'w-5 h-5 text-red-600 dark:text-red-400') 
                                                                {{ __('referrals.actions.delete_request') }}
                                                            </a>
                                                        </div>
                                                    @else
                                                        <div class="py-1">
                                                            <a href="#" wire:click.prevent="process('{{ $referral['id'] }}', '{{ $referral['subject']['identifier']['value'] ?? '' }}')" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                                @icon('referrals', 'w-5 h-5 text-gray-600 dark:text-gray-300') 
                                                                {{ __('referrals.actions.take_in_work') }}
                                                            </a>
                                                            <a href="#" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300') 
                                                                {{ __('forms.view_details') }}
                                                            </a>
                                                            <a href="#" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                                @icon('printer', 'w-5 h-5 text-gray-600 dark:text-gray-300') 
                                                                {{ __('referrals.actions.print_memo') }}
                                                            </a>
                                                        </div>
                                                        <div class="py-1">
                                                            <a href="#" wire:click.prevent="openCancelModal('{{ $referral['id'] }}')" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600">
                                                                @icon('cancel', 'w-5 h-5 text-red-600 dark:text-red-400') 
                                                                {{ __('referrals.actions.cancel_referral') }}
                                                            </a>
                                                            <a href="#" wire:click.prevent="openErrorModal('{{ $referral['id'] }}')" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600">
                                                                @icon('cancel', 'w-5 h-5 text-red-600 dark:text-red-400') 
                                                                {{ __('referrals.actions.mark_as_error') }}
                                                            </a>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Cancel Usage Modal -->
    <x-modal wire:model="showCancelModal" maxWidth="3xl">
        <div class="px-8 py-8">
            <h3 class="mb-4 text-[18px] font-bold text-[#1F2937] dark:text-white">
                {{ __('referrals.modals.cancel.title') }}
            </h3>
            
            <p class="mb-8 text-[15px] text-[#4B5563] dark:text-gray-400">
                {{ __('referrals.modals.cancel.warning') }}
            </p>

            <div class="mb-8">
                <label for="cancelLetter" class="mb-2 block text-[13px] font-medium text-[#1F2937] dark:text-white">
                    {{ __('referrals.modals.cancel.reason_label') }}
                </label>
                <textarea
                    id="cancelLetter"
                    wire:model="cancelExplanatoryLetter"
                    rows="5"
                    class="block w-full rounded-md border border-gray-300 bg-[#F9FAFB] p-3 text-[14px] text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 placeholder:text-[#9CA3AF]"
                    placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>

            <div class="flex items-center gap-4">
                <button
                    wire:click="$set('showCancelModal', false)"
                    type="button"
                    class="rounded-md border border-gray-300 bg-white px-6 py-2.5 text-[14px] font-medium text-[#374151] hover:bg-gray-50 focus:outline-none dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    {{ __('forms.cancel') }}
                </button>
                <button
                    wire:click="confirmCancelUsage"
                    type="button"
                    class="rounded-md bg-[#B91C1C] px-6 py-2.5 text-[14px] font-medium text-white hover:bg-red-800 focus:outline-none disabled:opacity-50"
                    {{ empty(trim($cancelExplanatoryLetter ?? '')) ? 'disabled' : '' }}
                >
                    {{ __('referrals.modals.cancel.btn_confirm') }}
                </button>
            </div>
        </div>
    </x-modal>

    <!-- Error Modal (Позначити помилковим) -->
    <x-modal wire:model="showErrorModal" maxWidth="3xl">
        <div class="px-8 py-8">
            <h3 class="mb-6 text-[18px] font-bold text-[#1F2937] dark:text-white">
                {!! __('referrals.modals.error.title') !!}
            </h3>
            
            <p class="mb-8 text-[15px] text-[#4B5563] dark:text-gray-400 leading-relaxed">
                {!! __('referrals.modals.error.warning') !!}
            </p>

            <div class="mb-6">
                <label for="errorReason" class="mb-1 block text-[11px] text-[#6B7280] dark:text-gray-400">
                    {{ __('referrals.modals.error.reason_dropdown_label') }}
                </label>
                <select
                    id="errorReason"
                    wire:model="errorReason"
                    class="block w-full border-0 border-b border-gray-300 bg-transparent px-0 py-2.5 text-[14px] text-gray-900 focus:border-blue-600 focus:ring-0 dark:border-gray-600 dark:text-white"
                >
                    <option value="">{{ __('referrals.modals.error.dropdown_placeholder') }}</option>
                    <option value="entered_in_error">{{ __('referrals.modals.error.entered_in_error') }}</option>
                    <option value="other">{{ __('referrals.modals.error.other') }}</option>
                </select>
            </div>

            <div class="mb-10">
                <label for="errorLetter" class="mb-2 block text-[13px] font-medium text-[#1F2937] dark:text-white">
                    {{ __('referrals.modals.error.reason_label') }}
                </label>
                <textarea
                    id="errorLetter"
                    wire:model="errorExplanatoryLetter"
                    rows="5"
                    class="block w-full rounded-md border border-gray-300 bg-[#F9FAFB] p-3 text-[14px] text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 placeholder:text-[#9CA3AF]"
                    placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>

            <div class="flex items-center gap-4">
                <button
                    wire:click="$set('showErrorModal', false)"
                    type="button"
                    class="rounded-md border border-gray-300 bg-white px-6 py-2.5 text-[14px] font-medium text-[#374151] hover:bg-gray-50 focus:outline-none dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    {{ __('forms.cancel') }}
                </button>
                <button
                    wire:click="confirmErrorUsage"
                    type="button"
                    class="rounded-md bg-[#B91C1C] px-6 py-2.5 text-[14px] font-medium text-white hover:bg-red-800 focus:outline-none disabled:opacity-50"
                    {{ empty(trim($errorReason ?? '')) || empty(trim($errorExplanatoryLetter ?? '')) ? 'disabled' : '' }}
                >
                    {{ __('referrals.modals.error.btn_confirm') }}
                </button>
            </div>
        </div>
    </x-modal>
</div>
