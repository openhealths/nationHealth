{{-- Both ids are forwarded: the layout switches its whole navigation between the person
     and preperson route families based on which one is set. --}}
<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        <div class="flex flex-wrap gap-2">
            @can('createNewborn', \App\Models\MedicalEvents\Sql\Composition::class)
                <a
                    href="{{ $this->createNewbornUrl }}"
                    class="button-primary-outline flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('compositions.actions.create_newborn') }}
                </a>
            @endcan
            @can('createTempDisability', \App\Models\MedicalEvents\Sql\Composition::class)
                <a
                    href="{{ $this->createTempDisabilityUrl }}"
                    class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('compositions.actions.create_temp_disability') }}
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('compositions.search_title') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select wire:model="filterType" name="filterType" id="filterType" class="input-select peer w-full">
                        <option value="">{{ __('forms.select') }} ...</option>
                        @foreach ($this->types as $type)
                            <option value="{{ $type['value'] }}">{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                    <label for="filterType" class="label"> {{ __('compositions.filter.type') }} </label>
                </div>

                <div class="form-group group">
                    <select
                        wire:model="filterStatus"
                        name="filterStatus"
                        id="filterStatus"
                        class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        @foreach ($this->statuses as $status)
                            <option value="{{ $status['value'] }}">{{ $status['label'] }}</option>
                        @endforeach
                    </select>
                    <label for="filterStatus" class="label"> {{ __('compositions.filter.status') }} </label>
                </div>

                <div class="form-group group">
                    <div class="relative">
                        <input
                            wire:model="filterEncounterId"
                            type="text"
                            name="filterEncounterId"
                            id="filterEncounterId"
                            class="input peer w-full"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label for="filterEncounterId" class="label"> {{ __('compositions.filter.encounter') }} </label>
                        <button
                            type="button"
                            wire:click="$set('filterEncounterId', '')"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            x-show="$wire.filterEncounterId"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="search"
                    class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                >
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('forms.search') }}</span>
                </button>
                <button type="button" wire:click="resetFilters" class="button-primary-outline-red px-5 py-2.5 text-sm">
                    {{ __('patients.reset_filters') }}
                </button>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedCompositions as $composition)
                    <div class="record-inner-card" wire:key="composition-{{ $composition->id }}">
                        <div class="record-inner-header">
                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('compositions.columns.title') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $composition->title ?: '-' }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('compositions.columns.status') }}</div>
                                <div>
                                    <span @class([$composition->status->color()])>
                                        {{ $composition->status->label() }}
                                    </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div
                                    x-data="{ open: false }"
                                    @click.outside="open = false"
                                    @keydown.escape.prevent.stop="open = false"
                                    class="relative"
                                >
                                    <button
                                        @click="open = ! open"
                                        :aria-expanded="open"
                                        type="button"
                                        class="record-inner-action-btn cursor-pointer rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition.origin.top.right
                                        class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                    >
                                        <button
                                            type="button"
                                            wire:click="viewComposition('{{ $composition->uuid }}')"
                                            @click="open = false"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="loadPrintForm('{{ $composition->uuid }}')"
                                            @click="open = false"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('document', 'w-5 h-5 text-gray-500')
                                            {{ __('compositions.actions.print') }}
                                        </button>

                                        @can('cancel', $composition)
                                            <button
                                                type="button"
                                                wire:click="openCancellationModal('{{ $composition->uuid }}')"
                                                @click="open = false"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-red-600 transition-colors hover:bg-gray-50 dark:text-red-400 dark:hover:bg-gray-600"
                                            >
                                                @icon('close', 'w-5 h-5')
                                                {{ __('compositions.actions.cancel') }}
                                            </button>
                                        @endcan

                                        @can('resendErln', $composition)
                                            <button
                                                type="button"
                                                wire:click="openErlnResendModal('{{ $composition->uuid }}')"
                                                @click="open = false"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('refresh', 'w-5 h-5 text-gray-500')
                                                {{ __('compositions.actions.resend_erln') }}
                                            </button>
                                        @endcan

                                        @if ($composition->hasReadContext)
                                            <button
                                                type="button"
                                                wire:click="refreshIntegration('{{ $composition->uuid }}')"
                                                @click="open = false"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('refresh', 'w-5 h-5 text-gray-500')
                                                {{ __('compositions.actions.refresh_integration') }}
                                            </button>
                                        @endif

                                        @if (
                                            $composition->isTempDisability
                                                                                                                                                                    && $composition->status->isCancellable()
                                                                                                                                                                    && $personId
)
                                            <a
                                                href="{{ $this->refineUrl($composition) }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('document', 'w-5 h-5 text-gray-500')
                                                {{ __('compositions.actions.refine') }}
                                            </a>
                                            <a
                                                href="{{ $this->continueUrl($composition) }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('plus', 'w-5 h-5 text-gray-500')
                                                {{ __('compositions.actions.continue') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="mb-4 grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3 lg:grid-cols-5">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('compositions.columns.type') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $composition->type->label() }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('compositions.columns.category') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $composition->category?->label() ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('compositions.columns.period_start') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $composition->eventPeriodStartDate ?: '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('compositions.columns.period_end') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $composition->eventPeriodEndDate ?: '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('compositions.columns.date') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $composition->compositionDateFormatted ?: '-' }}
                                        </div>
                                    </div>
                                </div>

                                @if ($composition->isTempDisability && $composition->erlnStatus)
                                    <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-2">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('compositions.columns.erln_status') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ $composition->erlnRecordNumber ?: $composition->erlnStatus }}
                                            </div>
                                        </div>
                                        @if ($composition->erlnStatusMessage)
                                            <div class="min-w-0">
                                                <div class="record-inner-label text-[10px] uppercase">
                                                    {{ __('compositions.erln_resend.error_message') }}
                                                </div>
                                                <div class="record-inner-value text-[14px] font-semibold break-words text-red-600 dark:text-red-400">
                                                    {{ $composition->erlnStatusMessage }}
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">ID ЕСОЗ</div>
                                    <div class="record-inner-id-value">{{ $composition->uuid }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('compositions.columns.encounter') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $composition->encounterUuid ?: '-' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>

            <div class="mt-4">{{ $this->paginatedCompositions->links() }}</div>
        </div>
    </div>

    @include('livewire.composition.composition-show')

    <x-dialog-modal maxWidth="3xl" id="modal-composition-print" wire:model.live="showPrintModal">
        <x-slot name="title">{{ __('compositions.print.title') }}</x-slot>

        <x-slot name="content">
            {{--
                TV 3.8.1.1.5.1 and 3.8.2.8.3.1 forbid adding logos, adverts or any other
                content to the form eHealth returns, so it is rendered verbatim in a
                sandboxed iframe rather than parsed and restyled.
            --}}
            <iframe
                id="composition-print-iframe"
                srcdoc="{{ $printFormHtml }}"
                class="w-full border-0"
                style="min-height: 600px"
                sandbox="allow-same-origin"
            ></iframe>
        </x-slot>

        <x-slot name="footer">
            <button
                type="button"
                onclick="document.getElementById('composition-print-iframe').contentWindow.print()"
                id="btn-print-iframe"
                class="button-primary px-5 py-2 text-sm"
            >
                {{ __('compositions.actions.print') }}
            </button>
            <button type="button" wire:click="closePrintModal" class="button-primary-outline ml-2 px-5 py-2 text-sm">
                {{ __('forms.close') }}
            </button>
        </x-slot>
    </x-dialog-modal>

    @include('livewire.composition.composition-cancellation')

    <x-dialog-modal id="modal-erln-resend" wire:model.live="showErlnResendModal">
        <x-slot name="title">{{ __('compositions.erln_resend.title') }}</x-slot>

        <x-slot name="content">
            <p>{{ __('compositions.erln_resend.confirm_message') }}</p>
        </x-slot>

        <x-slot name="footer">
            <button
                type="button"
                wire:click="resendErln"
                id="btn-confirm-erln-resend"
                class="button-primary px-5 py-2 text-sm"
            >
                {{ __('compositions.erln_resend.confirm_button') }}
            </button>
            <button
                type="button"
                wire:click="closeErlnResendModal"
                class="button-primary-outline ml-2 px-5 py-2 text-sm"
            >
                {{ __('forms.cancel') }}
            </button>
        </x-slot>
    </x-dialog-modal>

    <x-forms.loading />
</x-layouts.patient>
