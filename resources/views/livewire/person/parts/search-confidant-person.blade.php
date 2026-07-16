@use('Carbon\CarbonImmutable')

<div x-data="{ showFilter: true }">
    <section>
        <div class="text-gray-900 dark:text-white text-xl leading-normal mb-6">
            {{ __('patients.patient_legal_representative') }}
        </div>
        @include('livewire.person.parts.search-filter', ['context' => 'confidantPerson'])

        <div class="py-4">
            <button type="button" wire:click.prevent="searchForPerson" class="flex items-center gap-2 button-primary">
                @icon('search', 'w-4 h-4')
                <span>{{ __('patients.search_for_confidant') }}</span>
            </button>
        </div>
    </section>

    <!-- Patient list -->
    <div class="my-6">
        @if($confidantPerson && count($confidantPerson) > 0)
            <div class="flex flex-col h-auto">
                <div class="inline-block align-middle">
                    <div class="overflow-hidden shadow">
                        <x-tables.table align="left">
                            <x-slot name="headers"
                                    :list="[__('forms.full_name'), __('forms.phone'), __('Д.Н.'), __('forms.rnokpp') . '(' . __('forms.ipn') . ')', __('forms.action')]"
                            >
                            </x-slot>
                            <x-slot name="tbody">
                                @foreach($confidantPerson as $confidantPatient)
                                    @php
                                        $confidantPrimaryName = collect($confidantPatient['names'] ?? [])
                                            ->firstWhere('language', 'uk') ?? ($confidantPatient['names'][0] ?? []);
                                    @endphp
                                    <tr wire:key="{{ $confidantPatient['personUuid'] ?? $confidantPatient['id'] }}">
                                        <td class="p-4 text-sm font-normal text-gray-500 whitespace-nowrap dark:text-gray-400">
                                            <p class="text-base text-gray-900 dark:text-white">
                                                {{ $confidantPrimaryName['lastName'] ?? '' }} {{ $confidantPrimaryName['firstName'] ?? '' }} {{ $confidantPrimaryName['secondName'] ?? '' }}
                                            </p>
                                        </td>
                                        <td class="p-4 text-sm font-normal text-gray-500 whitespace-nowrap dark:text-gray-400">
                                            <p class="text-base text-gray-500 dark:text-white">
                                                {{ $confidantPatient['phones'][0]['number'] ?? '-' }}
                                            </p>
                                        </td>
                                        <td class="p-4 text-sm font-semibold text-gray-900 whitespace-nowrap dark:text-gray-400">
                                            <p class="text-base dark:text-white">
                                                {{ data_get($confidantPatient, 'birthDate')
                                                    ? CarbonImmutable::parse(data_get($confidantPatient, 'birthDate'))->format('j.m.Y')
                                                    : '' }}
                                            </p>
                                        </td>
                                        <td class="p-4 text-sm font-semibold text-gray-500 whitespace-nowrap dark:text-gray-400">
                                            <p class="text-base dark:text-white">
                                                {{ $confidantPatient['taxId'] ?? '-' }}
                                            </p>
                                        </td>
                                        <td>
                                            @if(($selectedConfidantPersonId === ($confidantPatient['id'] ?? ''))
                                                || ($selectedConfidantPersonId === ($confidantPatient['personUuid'] ?? '')))
                                                <button type="button"
                                                        class="cursor-pointer flex items-center gap-1 text-red-600"
                                                        wire:click.prevent="removeConfidantPerson"
                                                        @click="showFilter = true"
                                                >
                                                    @icon('delete', 'w-4 h-4')
                                                    <span class="text-sm font-medium">{{ __('forms.delete') }}</span>
                                                </button>
                                            @else
                                                <button type="button"
                                                        class="cursor-pointer flex items-center gap-1 text-blue-600"
                                                        wire:click.prevent="chooseConfidantPerson('{{ $confidantPatient['personUuid'] ?? $confidantPatient['id'] }}')"
                                                >
                                                    @icon('button', 'w-10 h-10')
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </x-slot>
                        </x-tables.table>
                    </div>
                </div>
            </div>
        @elseif($searchPerformed && empty($confidantPerson))
            <div class="rounded-lg p-4 bg-gray-100 dark:bg-gray-700">
                <div class="flex items-center gap-2">
                    @icon('alert-circle', 'w-4.5 h-4.5 dark:text-white')
                    <p class="font-semibold default-p">{{ __('patients.nobody_found') }}</p>
                </div>
                <span class="default-p">{{ __('patients.try_change_search_parameters') }}</span>
            </div>
        @endif
    </div>
</div>

