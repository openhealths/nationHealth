@php
    $additionalActionLinks = [
        'prescriptions' => ['label' => __('encounters.add_prescription'), 'method' => 'openEncounterEPrescriptionDrawer'],
        'outgoing-referrals' => ['label' => __('encounters.add_referral'), 'method' => 'openEncounterReferralDrawer'],
        'medical-reports' => [
            'label' => __('encounters.add_medical_report'),
            'href' => $this->createCompositionFromEncounterUrl,
        ],
        'care-plans' => [
            'label' => __('encounters.add_care_plan'),
            'href' => route('care-plans.create-by-encounter', [legalEntity(), 'encounter' => $encounterId]),
        ],
        'device-requests' => [
            'label' => __('encounters.open_device_requests'),
            'href' => route('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $personId]),
            'icon' => 'arrow-right',
        ],
    ];

    if ($isReadonly && $this->isFinished && $personId !== null
        && Auth::user()->can('create', [\App\Models\MedicalEvents\Sql\DeviceRequestRequest::class, legalEntity()])) {
        $additionalActionLinks['device-requests'] = [
            'label' => __('device-requests.add_request'),
            'method' => 'openDeviceRequest',
        ];
    }

    $additionalActionRecords = $additionalActionRecords ?? [];
@endphp

<div class="mt-10 border-t border-gray-100 pt-10 dark:border-gray-700">
    <h3 class="mb-6 text-[17px] font-semibold text-gray-900 dark:text-gray-100">
        {{ __('encounters.additional_actions') }}
    </h3>

    <div class="space-y-6">
        @foreach ($footerItems as $section)
            @php($action = $additionalActionLinks[$section['id']])
            <section
                id="block-{{ $section['id'] }}"
                class="scroll-mt-16 rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
                wire:key="encounter-additional-{{ $section['id'] }}"
            >
                <button
                    type="button"
                    @click="toggle('{{ $section['id'] }}')"
                    :aria-expanded="activeSections.includes('{{ $section['id'] }}')"
                    aria-controls="additional-content-{{ $section['id'] }}"
                    class="flex w-full cursor-pointer items-center justify-between gap-3 p-5 text-left focus-visible:outline-2 focus-visible:outline-blue-600"
                >
                    <span class="flex min-w-0 items-center gap-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center [&>svg]:h-full [&>svg]:w-full" aria-hidden="true">
                            @icon($section['icon'])
                        </span>
                        <span>{{ $section['label'] }}</span>
                    </span>
                    <span class="shrink-0 text-gray-500 transition-transform" :class="activeSections.includes('{{ $section['id'] }}') ? '' : '-rotate-90'">
                        @icon('chevron-down', 'w-4 h-4')
                    </span>
                </button>

                <div id="additional-content-{{ $section['id'] }}" x-show="activeSections.includes('{{ $section['id'] }}')" class="px-5 pb-5">
                    @foreach ($additionalActionRecords[$section['id']] ?? [] as $record)
                        <x-encounter.record-card :record="$record" :section-id="$section['id']" />
                    @endforeach

                    <div @class(['mt-4' => !empty($additionalActionRecords[$section['id']])])>
                        @if (!empty($action['href']))
                            <a
                                href="{{ $action['href'] }}"
                                class="inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                @icon($action['icon'] ?? 'plus', 'w-3.5 h-3.5')
                                <span>{{ $action['label'] }}</span>
                            </a>
                        @elseif (array_key_exists('href', $action))
                            <button
                                type="button"
                                disabled
                                title="{{ __('encounters.medical_report_creation_unavailable') }}"
                                class="inline-flex cursor-not-allowed items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-gray-500"
                            >
                                @icon('plus', 'w-3.5 h-3.5')
                                <span>{{ $action['label'] }}</span>
                            </button>
                        @else
                            <button
                                type="button"
                                @isset($action['method']) wire:click="{{ $action['method'] }}" @endisset
                                class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                @icon('plus', 'w-3.5 h-3.5')
                                <span>{{ $action['label'] }}</span>
                            </button>
                        @endif
                    </div>
                </div>
            </section>
        @endforeach
    </div>
</div>
