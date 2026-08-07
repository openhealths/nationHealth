<x-layouts.patient
    :personId="$personId"
    :uuid="$uuid"
    :patientFullName="$patientFullName"
    :hideNavigation="$allowsPatientChange"
>
    <div class="breadcrumb-form shift-content p-4">
        <div
            @scroll-to-error.window="
                setTimeout(() => {
                    const errorElement = document.querySelector('.text-error, .is-invalid, [aria-invalid=\'true\']');
                    if (! errorElement) return;
                    const block = errorElement.closest('[id]');
                    if (block && block.id && activeSection !== undefined) {
                        activeSection = block.id;
                    }
                    $nextTick(() => {
                        errorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        const input =
                            errorElement.previousElementSibling ||
                            errorElement.closest('div')?.querySelector('input, select, textarea');
                        if (input && typeof input.focus === 'function') {
                            input.focus();
                        }
                    });
                }, 50)
            "
            x-data="{ activeSection: 'doctors' }"
            class="flex flex-col gap-8 lg:flex-row lg:gap-12"
        >
            <!-- Main Content -->
            <div class="flex-1 space-y-6 pb-24">
                <div
                    id="doctors"
                    class="scroll-mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="record-inner-header border-b border-gray-100 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            @icon('doctor', 'w-5 h-5 inline mr-2')
                            {{ __('care-plan.doctors') }}
                        </h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.doctors')
                    </div>
                </div>

                <div
                    id="patient_data"
                    class="scroll-mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="record-inner-header border-b border-gray-100 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            @icon('patients', 'w-5 h-5 inline mr-2')
                            {{ __('care-plan.patient_data') }}
                        </h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.patient_data')
                    </div>
                </div>

                <div
                    id="care_plan_data"
                    class="scroll-mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="record-inner-header border-b border-gray-100 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            @icon('contracts', 'w-5 h-5 inline mr-2')
                            {{ __('care-plan.care_plan_data') }}
                        </h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.care_plan_data')
                    </div>
                </div>

                <div
                    id="condition_diagnosis"
                    class="scroll-mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="record-inner-header border-b border-gray-100 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            @icon('alert-circle', 'w-5 h-5 inline mr-2')
                            {{ __('care-plan.condition_diagnosis') }}
                        </h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.condition_diagnosis')
                    </div>
                </div>

                <div
                    id="supporting_information"
                    class="scroll-mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="record-inner-header border-b border-gray-100 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            @icon('file-text', 'w-5 h-5 inline mr-2')
                            {{ __('care-plan.supporting_information') }}
                        </h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.supporting-information')
                    </div>
                </div>

                <div
                    id="additional_info"
                    class="scroll-mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="record-inner-header border-b border-gray-100 p-4 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            @icon('settings', 'w-5 h-5 inline mr-2')
                            {{ __('care-plan.additional_info') }}
                        </h3>
                    </div>
                    <div class="p-6">
                        @include('livewire.care-plan.parts.additional_info', ['context' => 'create'])
                    </div>
                </div>

                <div class="flex gap-4 border-t border-gray-100 pt-6 dark:border-gray-700">
                    @if (isset($carePlan) && $carePlan->exists)
                        <button wire:click.prevent="delete" type="button" class="button-minor">
                            {{ __('forms.delete') ?? 'Видалити' }}
                        </button>
                    @else
                        <button wire:click.prevent="cancel" type="button" class="button-minor">
                            {{ __('forms.back') ?? 'Назад' }}
                        </button>
                    @endif

                    <button wire:click.prevent="save" type="submit" class="button-primary-outline">
                        {{ __('forms.save') }}
                    </button>

                    <button type="button" wire:click="startSigningProcess" class="button-primary">
                        {{ __('forms.save_and_send') }}
                    </button>
                </div>
            </div>

            <!-- Right Sidebar Navigation -->
            <div class="sticky top-6 mt-4 w-full flex-shrink-0 space-y-1 self-start lg:mt-0 lg:w-[280px]">
                @php
                    $navItems = [
                        ['id' => 'doctors', 'label' => __('care-plan.doctors'), 'icon' => 'doctor'],
                        ['id' => 'patient_data', 'label' => __('care-plan.patient_data'), 'icon' => 'patients'],
                        ['id' => 'care_plan_data', 'label' => __('care-plan.care_plan_data'), 'icon' => 'contracts'],
                        ['id' => 'condition_diagnosis', 'label' => __('care-plan.condition_diagnosis'), 'icon' => 'alert-circle'],
                        ['id' => 'supporting_information', 'label' => __('care-plan.supporting_information'), 'icon' => 'file-text'],
                        ['id' => 'additional_info', 'label' => __('care-plan.additional_info'), 'icon' => 'settings'],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    <button
                        @click="
                                activeSection = '{{ $item['id'] }}';
                                document.getElementById('{{ $item['id'] }}').scrollIntoView({ behavior: 'smooth', block: 'start' });
                            "
                        type="button"
                        :class="activeSection === '{{ $item['id'] }}' ? 'summary-sidebar-btn-active' : 'summary-sidebar-btn-inactive'"
                        class="summary-sidebar-btn"
                    >
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center">
                            @icon($item['icon'], 'w-5 h-5')
                        </span>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @include('livewire.care-plan.modals.authentication')
    @include('livewire.care-plan.modals.method-selection')

    {{-- Async approval job polling — isolated to avoid UI flickering --}}
    @if ($isPolling)
        <div
            x-data="{
                elapsed: 0,
                timer: null,
                init() {
                    this.timer = setInterval(() => {
                        this.elapsed++;
                    }, 1000);
                },
                destroy() {
                    if (this.timer) clearInterval(this.timer);
                },
            }"
            x-init="init()"
            class="fixed right-6 bottom-6 z-50 w-full max-w-md transition-all duration-300 ease-in-out"
            wire:key="approval-polling-notification"
        >
            {{-- Isolated background polling element with keep-alive to prevent page jumps --}}
            <div wire:poll.3s.keep-alive="checkApprovalJobStatus" class="hidden"></div>

            <div class="relative overflow-hidden rounded-2xl border border-blue-200/80 bg-white/95 p-5 shadow-2xl backdrop-blur-md transition-all dark:border-blue-800/80 dark:bg-gray-900/95">
                {{-- Top animated accent line --}}
                <div class="absolute inset-x-0 top-0 h-1 w-full bg-blue-100 dark:bg-blue-950">
                    <div class="h-full w-1/3 animate-[pulse_1.5s_ease-in-out_infinite] rounded-full bg-gradient-to-r from-blue-500 via-indigo-500 to-blue-600 transition-all duration-500"></div>
                </div>

                <div class="flex items-start gap-4">
                    <div class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/80 dark:text-blue-400">
                        <svg class="h-6 w-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    <div class="flex-1 space-y-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ __('care-plan.approval_processing_title') }}
                            </h4>
                            <span class="inline-flex animate-pulse items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/60 dark:text-blue-300">
                                {{ __('care-plan.status.in_progress') }}
                            </span>
                        </div>

                        <p class="text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                            {{ __('care-plan.approval_processing') }}
                        </p>

                        {{-- Timeout hint & manual check button when background processing takes longer --}}
                        <div
                            x-show="elapsed >= 12"
                            x-cloak
                            x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800"
                        >
                            <p class="mb-2.5 text-xs text-amber-700 dark:text-amber-400">
                                {{ __('care-plan.approval_processing_timeout') }}
                            </p>
                            <button
                                type="button"
                                wire:click="checkApprovalJobStatus"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition-colors hover:bg-blue-100 focus:ring-2 focus:ring-blue-500/30 focus:outline-none dark:bg-blue-900/40 dark:text-blue-300 dark:hover:bg-blue-900/60"
                            >
                                <svg wire:loading.remove wire:target="checkApprovalJobStatus" class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <svg wire:loading wire:target="checkApprovalJobStatus" class="h-3.5 w-3.5 animate-spin text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                </svg>
                                <span>{{ __('care-plan.check_status_manually') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <x-signature-modal method="sign" />
</x-layouts.patient>
