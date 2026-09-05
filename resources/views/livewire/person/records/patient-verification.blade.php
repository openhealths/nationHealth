@php
    use App\Enums\Person\VerificationStatus;
    use App\Livewire\Person\Forms\PersonVerificationForm;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Models\Relations\PersonVerificationDetail;
@endphp

<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName" :activeTab="'verification'">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a
                href="{{ route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        <button
            wire:click.prevent="getVerificationStatus"
            type="button"
            class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="getVerificationStatus">
                @icon('refresh', 'w-4 h-4')
            </span>
            <span wire:loading wire:target="getVerificationStatus" class="animate-spin">
                @icon('refresh', 'w-4 h-4')
            </span>
            <span>{{ __('forms.synchronise_with_eHealth') }}</span>
        </button>
    </x-slot>

    <div
        class="breadcrumb-form shift-content space-y-6 p-4"
        x-data="{
             showUpdateModal: false,
             reason: '{{ $form->verificationReason }}'
         }"
    >
        {{-- Warning Banner --}}
        @if ($this->hasVerificationIssues)
            <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/30 dark:bg-red-950/20">
                <div class="mt-0.5 shrink-0 text-red-600 dark:text-red-400">
                    @icon('alert-circle', 'w-5 h-5')
                </div>
                <div class="space-y-1 text-sm leading-relaxed">
                    <h4 class="font-bold text-red-700 dark:text-red-400">
                        {{ __('patient-verifications.warning_banner_title') }}
                    </h4>
                    <p class="text-red-600 dark:text-red-300">{{ __('patient-verifications.warning_banner_text') }}</p>
                    <p class="text-red-600 dark:text-red-300">
                        {{ __('patient-verifications.warning_banner_notice') }}
                    </p>
                </div>
            </div>
        @endif

        {{-- Verification Details Table --}}
        <div class="index-table-wrapper overflow-x-auto">
            <table class="index-table">
                <thead class="index-table-thead">
                    <tr>
                        <th class="index-table-th w-[20%] uppercase">{{ __('patient-verifications.direction') }}</th>
                        <th class="index-table-th w-[15%] whitespace-nowrap uppercase">
                            {{ __('patient-verifications.status_header') }}
                        </th>
                        <th class="index-table-th w-[15%] uppercase">
                            {{ __('patient-verifications.reason_header') }}
                        </th>
                        <th class="index-table-th w-[10%] uppercase">
                            {{ __('patient-verifications.comment_header') }}
                        </th>
                        <th class="index-table-th w-[40%] uppercase">
                            {{ __('patient-verifications.recommendations_header') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->verificationDetails as $detail)
                        <tr class="index-table-tr" wire:key="verification-row-{{ $detail->source->value }}">
                            <td class="index-table-td align-top font-semibold text-gray-900 dark:text-white">
                                {{ __('patient-verifications.sources.' . $detail->source->value) }}
                            </td>
                            <td class="index-table-td align-top whitespace-nowrap">
                                <span class="{{ $detail->verificationStatus->color() }} inline-block whitespace-nowrap">
                                    {{ $detail->verificationStatus->label() }}
                                </span>
                            </td>
                            <td class="index-table-td align-top whitespace-nowrap text-gray-600 dark:text-gray-400">
                                {{ $this->dictionaries['PERSON_VERIFICATION_STATUS_REASONS'][$detail->verificationReason] }}
                            </td>
                            <td class="index-table-td align-top text-gray-600 dark:text-gray-400">
                                {{ $detail->verificationComment ?? '-' }}
                            </td>
                            <td class="index-table-td align-top text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                                {{
                                    $detail->verificationStatus === VerificationStatus::NOT_VERIFIED
                                    ? __(
                                        'patient-verifications.recommendations.' . $detail->source->value,
                                        ['comment' => $detail->verificationComment ?? '-']
                                    )
                                    : '-'
                                }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="index-table-td !py-6 text-center text-gray-400">
                                {{ __('patient-verifications.empty_description') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Action Button --}}
        @can('update', [PersonVerificationDetail::class, $person])
            <div>
                <button
                    type="button"
                    @click="showUpdateModal = true"
                    class="button-primary-outline inline-flex items-center gap-2"
                >
                    {{ __('patient-verifications.update_data') }}
                </button>
            </div>
        @endcan

        {{-- Update Verification Modal --}}
        <div
            x-show="showUpdateModal"
            x-cloak
            class="fixed inset-0 z-50 overflow-y-auto"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
            @keydown.escape.window="showUpdateModal = false"
        >
            {{-- Backdrop --}}
            <div
                x-show="showUpdateModal"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-[#343e4d]/80 transition-opacity"
                @click="showUpdateModal = false"
            ></div>

            {{-- Modal Dialog --}}
            <div class="relative flex min-h-screen items-center justify-center p-4 sm:p-6">
                <div
                    x-show="showUpdateModal"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    class="relative w-full max-w-4xl transform rounded-xl bg-white p-8 text-left shadow-2xl transition-all sm:p-12 dark:bg-gray-800"
                    @click.stop
                >
                    {{-- Title --}}
                    <h3 class="mb-8 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white" id="modal-title">
                        {{ __('patient-verifications.update_dracs_title') }}
                    </h3>

                    {{-- Form --}}
                    <form @submit.prevent="showUpdateModal = false" class="space-y-6">
                        {{-- Status --}}
                        <div class="max-w-xs space-y-1.5">
                            <label
                                for="modal-status"
                                class="block text-xs font-normal text-gray-500 dark:text-gray-400"
                            >
                                {{ __('forms.status.label') }}
                            </label>
                            <div class="border-b border-gray-300 focus-within:border-blue-600 dark:border-gray-600">
                                <select
                                    id="modal-status"
                                    wire:model="form.verificationStatus"
                                    class="w-full cursor-pointer border-0 bg-transparent p-0 py-1.5 text-sm font-normal text-gray-900 focus:outline-none dark:text-white"
                                >
                                    <option value="VERIFIED" class="dark:bg-gray-800">
                                        {{ __('patient-verifications.statuses.verified') }}
                                    </option>
                                </select>
                            </div>

                            @error('form.verificationStatus')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Reason --}}
                        <div class="max-w-xs space-y-1.5">
                            <label
                                for="modal-reason"
                                class="block text-xs font-normal text-gray-500 dark:text-gray-400"
                            >
                                {{ __('patient-verifications.reason_field') }}
                            </label>
                            <div class="border-b border-gray-300 focus-within:border-blue-600 dark:border-gray-600">
                                <select
                                    id="modal-reason"
                                    wire:model="form.verificationReason"
                                    @change="
                                        reason = $event.target.value;

                                        if (reason !== '{{ PersonVerificationForm::REASON_MANUAL_DECEASED }}') {
                                            $wire.set('form.deathDate', '', false);
                                        }
                                    "
                                    class="w-full cursor-pointer border-0 bg-transparent p-0 py-1.5 text-sm font-normal text-gray-900 focus:outline-none dark:text-white"
                                >
                                    @foreach (PersonVerificationForm::DRACS_DEATH_REASONS as $reason)
                                        <option value="{{ $reason }}" class="dark:bg-gray-800">
                                            {{ $this->dictionaries['PERSON_VERIFICATION_STATUS_REASONS'][$reason] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            @error('form.verificationReason')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Death date --}}
                        <template x-if="reason === '{{ PersonVerificationForm::REASON_MANUAL_DECEASED }}'">
                            <div class="max-w-xs space-y-1.5">
                                <label
                                    for="modal-death-date"
                                    class="block text-xs font-normal text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('patient-verifications.death_date') }}
                                </label>
                                <div class="datepicker-wrapper">
                                    <input
                                        id="modal-death-date"
                                        wire:model="form.deathDate"
                                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                        type="text"
                                        name="deathDate"
                                        class="datepicker-input with-leading-icon input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                        x-on:changeDate="$event.target.blur()"
                                    />
                                </div>

                                @error('form.deathDate')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </template>

                        {{-- Comment --}}
                        <div class="space-y-2 pt-2">
                            <label for="modal-comment" class="block text-sm font-bold text-gray-900 dark:text-white">
                                {{ __('forms.comment') }}
                            </label>
                            <textarea
                                id="modal-comment"
                                wire:model="form.verificationComment"
                                rows="5"
                                class="w-full resize-none rounded-lg border border-gray-200 bg-transparent p-4 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:text-white"
                                placeholder="{{ __('patient-verifications.comment_death_confirmed') }}"
                            ></textarea>

                            @error('form.verificationComment')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Footer Buttons --}}
                        <div class="flex items-center gap-4 pt-4">
                            <button type="button" @click="showUpdateModal = false" class="button-minor px-6 py-2.5">
                                {{ __('forms.cancel') }}
                            </button>

                            <button
                                type="button"
                                @click="
                                    if (await $wire.confirmDeathVerification()) {
                                        showUpdateModal = false;
                                    }
                                "
                                wire:target="confirmDeathVerification"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                class="button-primary px-6 py-2.5 shadow-sm transition-colors"
                            >
                                <span wire:loading.remove wire:target="confirmDeathVerification">
                                    {{ __('patient-verifications.update_data_in_ehealth') }}
                                </span>
                                <span wire:loading wire:target="confirmDeathVerification">
                                    {{ __('forms.loading') }}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <x-signature-modal method="signDeathVerification" />
    </div>
</x-layouts.patient>
