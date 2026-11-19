@if ($referralPrototypeEnabled || count($references) > 0)
    <div class="table-scroll">
        <table
            class="table-input border-b-4 border-gray-200 dark:border-gray-600"
            @if ($referralPrototypeEnabled) x-show="($wire.encounterReferralForm[@js($field)] || []).length > 0" @endif
            data-referral-reference-table="{{ $field }}:{{ $referenceGroup }}"
            wire:key="referral-reference-table-{{ $field }}-{{ $referenceGroup }}"
        >
            <thead
                class="thead-input border-b border-gray-100 dark:border-gray-600"
            >
                <tr>
                    <th scope="col" class="th-input w-32">
                        {{ __('forms.date') }}
                    </th>
                    <th scope="col" class="th-input">{{ __('forms.name') }}</th>
                    <th scope="col" class="th-input w-16 text-center">
                        {{ __('forms.action') }}
                    </th>
                </tr>
            </thead>
            <tbody>
                @if ($referralPrototypeEnabled)
                    <template
                        x-for="reference in ($wire.encounterReferralForm[@js($field)] || [])"
                        :key="previewReferenceKey(reference)"
                    >
                        <tr
                            class="border-b border-gray-200 last:border-b-0 dark:border-gray-700"
                            :data-referral-reference="@js($field) + ':' + previewReferenceKey(reference)"
                        >
                            <td
                                class="td-input whitespace-nowrap text-gray-900 dark:text-gray-300"
                                x-text="
                                    reference.date ||
                                    $wire.encounterReferralReferenceOptions?.[
                                        reference.type + ':' + reference.uuid
                                    ]?.date ||
                                    '—'
                                "
                            ></td>
                            <td
                                class="td-input break-words text-gray-900 dark:text-gray-100"
                                x-text="referenceName(reference, @js($field))"
                            ></td>
                            <td class="td-input text-center">
                                <button
                                    type="button"
                                    @click="removePreviewReference(@js($field), reference)"
                                    class="inline-flex cursor-pointer items-center justify-center rounded p-1.5 text-gray-900 hover:text-red-600 dark:text-gray-100 dark:hover:text-red-400"
                                    :aria-label="@js(__('forms.delete')) + ': ' + referenceName(reference, @js($field))"
                                >
                                    @icon ('trash', 'h-4 w-4')
                                </button>
                            </td>
                        </tr>
                    </template>
                @else
                    @foreach ($references as $reference)
                        @php
                        $type = (string) ($reference['type'] ?? '');
                        $uuid = (string) ($reference['uuid'] ?? '');
                        $option = $encounterReferralReferenceOptions[$type.':'.$uuid] ?? [];
                        $name = $reference['label'] ?? $option['label'] ?? $reference['displayValue'] ?? $reference['display_value'] ?? $reference['text'] ?? $uuid;
                        $referenceKey = md5(json_encode($reference));
                        $date = ($reference['date'] ?? $option['date'] ?? '') ?: '—';
                        $prefix = $field === 'supporting_info'
                            ? match ($type) {
                                'episode' => __('episodes.label'),
                                'condition' => __('conditions.condition_or_diagnosis'),
                                'observation' => __('observations.label'),
                                'diagnostic_report' => __('diagnostic-reports.label'),
                                default => '',
                            }
                            : '';
                    @endphp
                        <tr
                            class="border-b border-gray-200 last:border-b-0 dark:border-gray-700"
                            data-referral-reference="{{ $field }}:{{ $type }}:{{ $uuid }}"
                            wire:key="referral-reference-{{ $field }}-{{ $type }}-{{ $uuid }}-{{ $referenceKey }}"
                        >
                            <td
                                class="td-input whitespace-nowrap text-gray-900 dark:text-gray-300"
                            >
                                {{ $date }}
                            </td>
                            <td
                                class="td-input break-words text-gray-900 dark:text-gray-100"
                            >
                                {{ trim($prefix.' '.$name) }}
                            </td>
                            <td class="td-input text-center">
                                @if ($hasReferenceSearch)
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center justify-center rounded p-1.5 text-gray-900 hover:text-red-600 dark:text-gray-100 dark:hover:text-red-400"
                                    wire:click="removeReferralReference('{{ $field }}', '{{ $type }}', '{{ $uuid }}', '{{ $referenceKey }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="removeReferralReference"
                                    aria-label="{{ __('forms.delete') }}: {{ $name }}"
                                >
                                    @icon ('trash', 'h-4 w-4')
                                </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
@endif
