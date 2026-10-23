@use(App\Enums\Specimen\Status as SpecimenStatus)
@use(App\Models\MedicalEvents\Sql\Specimen)

<div>
    <livewire:components.x-message :key="time()" />
    <x-header-navigation class="items-start" x-data="{ showFilter: false }">
        <x-slot name="title">{{ __('specimens.title') }}</x-slot>
    </x-header-navigation>

    <section class="section-form mt-4">
        <div class="shift-content w-full max-w-7xl px-4 py-6 lg:py-10">
            <div class="mb-6 w-full" x-data="{ showAdditionalParams: false }">
                <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('specimens.search_specimens') }}</p>
                </div>

                <div class="form-row-4 mb-6">
                    <div class="form-group group">
                        <div class="relative">
                            <input
                                wire:model="searchId"
                                wire:keydown.enter="search"
                                x-mask="9999-9999-9999-9999"
                                type="text"
                                name="searchId"
                                id="searchId"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="searchId" class="label">{{ __('specimens.accession_identifier') }}</label>
                            <button
                                type="button"
                                wire:click="$set('searchId', '')"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.searchId"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="search"
                            class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            @if ($specimen)
                @php
                    $collectedDateTime = formatDisplayDateTime(convertToLocalTimezone(data_get($specimen, 'collection.collectedDateTime') ?? ''));
                    $collectedPeriodStart = formatDisplayDateTime(convertToLocalTimezone(data_get($specimen, 'collection.collectedPeriod.start') ?? ''));
                    $collectedPeriodEnd = formatDisplayDateTime(convertToLocalTimezone(data_get($specimen, 'collection.collectedPeriod.end') ?? ''));
                    $status = SpecimenStatus::from(data_get($specimen, 'status'));
                    $conceptLabel = static fn (?array $concept): string => $dictionaries[data_get($concept, 'coding.0.system')][data_get($concept, 'coding.0.code')] ?? '-';
                    $quantityLabel = static fn (?array $quantity): string => isset($quantity['value']) ? trim($quantity['value'] . ' ' . ($quantity['unit'] ?? '')) : '-';
                    $dateTimeLabel = static fn (?string $value): string => $value ? formatDisplayDateTime(convertToLocalTimezone($value)) : '-';
                    // Collected by the patient, the collector would name the patient, so only the role is shown then
                    $collector = data_get($specimen, 'collection.collector.identifier.type.coding.0.code') === 'patient'
                        ? __('forms.patient')
                        : data_get($specimen, 'collection.collector.displayValue') ?? data_get($specimen, 'collection.collector.identifier.value') ?? '-';
                @endphp
                <div class="space-y-4">
                    <div
                        class="record-inner-card"
                        wire:key="specimen-{{ data_get($specimen, 'uuid') }}"
                        x-data="{ showDetails: false }"
                    >
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <label for="specimenRecord" class="sr-only">{{ __('forms.select') }}</label>
                                <input type="checkbox" id="specimenRecord" class="default-checkbox h-5 w-5" />
                            </div>
                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">
                                    {{ __('specimens.specimen_number') }} {{ data_get($specimen, 'accessionIdentifier') ?? '-' }}
                                </div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ data_get($dictionaries, 'specimen_types.' . data_get($specimen, 'type.coding.0.code'), '-') }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-48">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="{{ $status->color() }}">{{ $status->label() }}</span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div
                                    class="relative flex justify-center"
                                    x-data="{
                                        open: false,
                                        toggle() {
                                            if (this.open) {
                                                return this.close();
                                            }
                                            this.$refs.button.focus();
                                            this.open = true;
                                        },
                                        close(focusAfter) {
                                            if (! this.open) return;
                                            this.open = false;
                                            focusAfter && focusAfter.focus();
                                        },
                                    }"
                                    @keydown.escape.prevent.stop="close($refs.button)"
                                    @focusin.window="! $refs.panel.contains($event.target) && close()"
                                >
                                    <button
                                        x-ref="button"
                                        @click="toggle()"
                                        :aria-expanded="open"
                                        type="button"
                                        class="inline-flex cursor-pointer items-center rounded-lg p-2 text-gray-500 hover:text-gray-800"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-white')
                                    </button>

                                    <div
                                        x-ref="panel"
                                        x-show="open"
                                        x-cloak
                                        x-transition
                                        class="absolute top-full right-0 z-50 mt-2 w-72 rounded bg-white shadow-lg dark:bg-gray-700"
                                        style="display: none"
                                    >
                                        <ul class="py-1 text-sm text-gray-700 dark:text-gray-200">
                                            <li>
                                                <button
                                                    type="button"
                                                    @click="
                                                        showDetails = ! showDetails;
                                                        close($refs.button);
                                                    "
                                                    class="flex w-full cursor-pointer items-center gap-2 px-5 py-2 whitespace-nowrap text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('eye', 'w-5 h-5')
                                                    <span x-show="! showDetails">{{ __('forms.view_details') }}</span>
                                                    <span
                                                        x-show="showDetails"
                                                        x-cloak
                                                    >{{ __('specimens.hide_details') }}</span>
                                                </button>
                                            </li>
                                            @can('process', [Specimen::class, $specimen])
                                                <li>
                                                    <button
                                                        type="button"
                                                        @click="
                                                            $wire.set(
                                                                'form.receivedDate',
                                                                new Date().toLocaleDateString('uk-UA'),
                                                                false,
                                                            );
                                                            $wire.set(
                                                                'form.receivedTime',
                                                                new Date().toLocaleTimeString('uk-UA', {
                                                                    hour: '2-digit',
                                                                    minute: '2-digit',
                                                                }),
                                                                false,
                                                            );
                                                            $wire.showProcessModal = true;
                                                            close($refs.button);
                                                        "
                                                        class="flex w-full cursor-pointer items-center gap-2 px-5 py-2 whitespace-nowrap text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('checkmark-circle', 'w-5 h-5 shrink-0')
                                                        {{ __('specimens.received_for_research') }}
                                                    </button>
                                                </li>
                                            @endcan
                                            @can('invalidate', [Specimen::class, $specimen])
                                                <li class="mt-1 border-t border-gray-100 pt-1 dark:border-gray-600">
                                                    <button
                                                        type="button"
                                                        @click="
                                                            $wire.set('form.invalidateReason', '', false);
                                                            $wire.showInvalidateModal = true;
                                                            close($refs.button);
                                                        "
                                                        class="flex w-full cursor-pointer items-center gap-2 px-5 py-2 text-left whitespace-nowrap text-red-600 transition-colors hover:bg-gray-100 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('cancel', 'w-5 h-5 shrink-0')
                                                        <span>{{ __('specimens.invalidate') }}</span>
                                                    </button>
                                                </li>
                                            @endcan
                                            @can('reject', [Specimen::class, $specimen])
                                                <li>
                                                    <button
                                                        type="button"
                                                        @click="
                                                            $wire.set('form.rejectReason', '', false);
                                                            $wire.showRejectModal = true;
                                                            close($refs.button);
                                                        "
                                                        class="flex w-full cursor-pointer items-center gap-2 px-5 py-2 text-left whitespace-nowrap text-red-600 transition-colors hover:bg-gray-100 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('cancel', 'w-5 h-5 shrink-0')
                                                        <span>{{ __('specimens.reject') }}</span>
                                                    </button>
                                                </li>
                                            @endcan
                                            @can('cancel', [Specimen::class, $specimen])
                                                <li>
                                                    <button
                                                        type="button"
                                                        @click="
                                                            $dispatch('open-specimen-cancellation', { id: '{{ data_get($specimen, 'uuid') }}' });
                                                            close($refs.button);
                                                        "
                                                        class="flex w-full cursor-pointer items-center gap-2 px-5 py-2 text-left whitespace-nowrap text-red-600 transition-colors hover:bg-gray-100 dark:hover:bg-gray-600"
                                                    >
                                                        @icon('cancel', 'w-5 h-5 shrink-0')
                                                        <span>{{ __('specimens.cancel') }}</span>
                                                    </button>
                                                </li>
                                            @endcan
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.collection') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @if ($collectedDateTime)
                                                {{ $collectedDateTime }}
                                            @else
                                                {{ $collectedPeriodStart ?: '-' }}
                                                @if ($collectedPeriodEnd)
                                                    -<br

                                                    />{{ $collectedPeriodEnd }}
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.parent_specimen') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @forelse (data_get($specimen, 'parent', []) as $parent)
                                                <div>{{ data_get($parent, 'identifier.value') }}</div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.containers_and_type') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @forelse (data_get($specimen, 'container', []) as $container)
                                                <div>
                                                    {{ data_get($container, 'identifier') }} , {{ data_get($dictionaries, 'specimen_container_types.' . data_get($container, 'type.coding.0.code'), '-') }}
                                                </div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.created_by') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            {{ data_get($specimen, 'registeredBy.displayValue') ?? '-' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.electronic_referral') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @forelse (data_get($specimen, 'request', []) as $request)
                                                <div>{{ data_get($request, 'identifier.value') }}</div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('forms.gender') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            {{ $dictionaries['GENDER'][data_get($specimen, 'identity.gender')] ?? '-' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.patient_age') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            {{ data_get($specimen, 'identity.age') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col md:w-64!">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('specimens.encounter') }}</div>
                                    <div class="record-inner-id-value font-semibold whitespace-nowrap">
                                        {{ data_get($specimen, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            x-show="showDetails"
                            x-cloak
                            class="space-y-6 border-t border-gray-200 p-4 text-sm dark:border-gray-700"
                        >
                            <div>
                                <div class="mb-3 font-semibold text-gray-900 dark:text-gray-100">
                                    {{ __('specimens.general_info') }}
                                </div>
                                <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3">
                                    <div>
                                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                                        <div class="record-inner-value break-all">
                                            {{ data_get($specimen, 'uuid') }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.status_reason') }}</div>
                                        <div class="record-inner-value">
                                            {{ $conceptLabel(data_get($specimen, 'statusReason')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.specimen_condition') }}</div>
                                        <div class="record-inner-value">
                                            {{ $conceptLabel(data_get($specimen, 'condition')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.date_time_received') }}</div>
                                        <div class="record-inner-value">
                                            {{ $dateTimeLabel(data_get($specimen, 'receivedTime')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.created_in_system') }}</div>
                                        <div class="record-inner-value">
                                            {{ $dateTimeLabel(data_get($specimen, 'ehealthInsertedAt')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.updated_in_system') }}</div>
                                        <div class="record-inner-value">
                                            {{ $dateTimeLabel(data_get($specimen, 'ehealthUpdatedAt')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('specimens.healthcare_service_where_collected') }}
                                        </div>
                                        <div class="record-inner-value">
                                            {{ data_get($specimen, 'managingOrganization.displayValue') ?? data_get($specimen, 'managingOrganization.identifier.value') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="md:col-span-2">
                                        <div class="record-inner-label">{{ __('specimens.note') }}</div>
                                        <div class="record-inner-value">{{ data_get($specimen, 'note') ?? '-' }}</div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-3 font-semibold text-gray-900 dark:text-gray-100">
                                    {{ __('specimens.collection_details') }}
                                </div>
                                <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3">
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.collector') }}</div>
                                        <div class="record-inner-value">{{ $collector }}</div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">
                                            {{ __('specimens.procedure_during_collection') }}
                                        </div>
                                        <div class="record-inner-value break-all">
                                            {{ data_get($specimen, 'collection.procedure.identifier.value') ?? '-' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.collection_duration') }}</div>
                                        <div class="record-inner-value">
                                            {{ $quantityLabel(data_get($specimen, 'collection.duration')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.material_amount') }}</div>
                                        <div class="record-inner-value">
                                            {{ $quantityLabel(data_get($specimen, 'collection.quantity')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.collection_method') }}</div>
                                        <div class="record-inner-value">
                                            {{ $conceptLabel(data_get($specimen, 'collection.method')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.body_site') }}</div>
                                        <div class="record-inner-value">
                                            {{ $conceptLabel(data_get($specimen, 'collection.bodySite')) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label">{{ __('specimens.fasting_status') }}</div>
                                        <div class="record-inner-value">
                                            {{ $conceptLabel(data_get($specimen, 'collection.fastingStatusCodeableConcept')) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @foreach (data_get($specimen, 'container', []) as $container)
                                <div wire:key="specimen-container-{{ $loop->index }}">
                                    <div class="mb-3 font-semibold text-gray-900 dark:text-gray-100">
                                        {{ __('specimens.container_number') }}{{ $loop->iteration }}
                                    </div>
                                    <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3">
                                        <div>
                                            <div class="record-inner-label">{{ __('specimens.identifier') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($container, 'identifier') }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('specimens.container_type') }}</div>
                                            <div class="record-inner-value">
                                                {{ $conceptLabel(data_get($container, 'type')) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">
                                                {{ __('specimens.container_description') }}
                                            </div>
                                            <div class="record-inner-value">
                                                {{ data_get($container, 'description') ?? '-' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('specimens.container_volume') }}</div>
                                            <div class="record-inner-value">
                                                {{ $quantityLabel(data_get($container, 'capacity')) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">
                                                {{ __('specimens.biomaterial_amount_in_container') }}
                                            </div>
                                            <div class="record-inner-value">
                                                {{ $quantityLabel(data_get($container, 'specimenQuantity')) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('specimens.additive') }}</div>
                                            <div class="record-inner-value">
                                                {{ $conceptLabel(data_get($container, 'additiveCodeableConcept')) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <livewire:specimen.specimen-cancellation
                    :patient-id="data_get($specimen, 'subject.identifier.value')"
                    :key="'specimen-cancellation-' . data_get($specimen, 'uuid')"
                />
            @else
                <x-nothing-found :description="null" />
            @endif
        </div>

        <x-forms.loading />
    </section>

    @include('livewire.specimen.parts.modals.process')
    @include('livewire.specimen.parts.modals.invalidate')
    @include('livewire.specimen.parts.modals.reject')
</div>
