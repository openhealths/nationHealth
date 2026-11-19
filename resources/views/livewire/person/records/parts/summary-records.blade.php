<div class="space-y-4">
    @foreach ($summaryCards as $index => $record)
        <article
            class="record-inner-card min-w-0"
            wire:key="summary-{{ $record['key'] }}"
        >
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input
                        type="checkbox"
                        class="default-checkbox h-5 w-5"
                        aria-label="{{ __('patient-summary.select_record', ['name' => $record['title']]) }}"
                    />
                </div>
                <div class="record-inner-column min-w-0 flex-1">
                    <div class="record-inner-label">
                        {{ $record['titleLabel'] }}
                    </div>
                    @if ($record['subtitle'] ?? null)
                        <div class="record-inner-label">
                            {{ __('specimens.identifier') }}: {{ $record['subtitle'] }}
                        </div>
                    @endif
                    <div class="record-inner-value break-words text-[16px]">
                        {{ $record['title'] }}
                    </div>
                </div>
                <div
                    class="record-inner-column-bordered w-full shrink-0 md:w-36"
                >
                    <div class="record-inner-label">
                        {{ $record['statusLabel'] }}
                    </div>
                    <div>
                        <span
                            class="{{ $record['statusClass'] }}"
                            >{{ $record['status'] }}</span
                        >
                    </div>
                </div>
                <div class="record-inner-action-col">
                    @if (($showEpisodeActions ?? false) && $record['href'])
                        @include ('livewire.person.records.parts.episode-actions', ['episode' => $episodes[$index]])
                    @else
                        <x-patient.record-actions :href="$record['href']">
                            @if (!$record['href'])
                                <button
                                    type="button"
                                    @click="viewedRecord = @js($record); showSummaryDetails = true; close()"
                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    @icon ('eye', 'w-5 h-5')
                                    {{ __('forms.view_details') }}
                                </button>
                            @endif
                        </x-patient.record-actions>
                    @endif
                </div>
            </div>
            <div class="record-inner-body">
                <div class="record-inner-grid-container min-w-0">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                        @foreach ($record['fields'] as $field)
                            <div class="min-w-0">
                                <dt class="record-inner-label">
                                    {{ $field['label'] }}
                                </dt>
                                <dd
                                    class="record-inner-subvalue break-words whitespace-pre-line"
                                >
                                    {{ $field['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                    @foreach ($record['groups'] as $group)
                        <div
                            class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700"
                        >
                            <h4 class="record-inner-label mb-3">
                                {{ $group['label'] }}
                            </h4>
                            <dl class="grid grid-cols-2 gap-4">
                                @foreach ($group['fields'] as $field)
                                    <div class="min-w-0">
                                        <dt class="record-inner-label">
                                            {{ $field['label'] }}
                                        </dt>
                                        <dd
                                            class="record-inner-subvalue break-words whitespace-pre-line"
                                        >
                                            {{ $field['value'] }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
                <div class="record-inner-id-col min-w-0">
                    @foreach ($record['ids'] as $identifier)
                        <div class="min-w-0">
                            <div class="record-inner-label">
                                {{ $identifier['label'] }}
                            </div>
                            <div class="record-inner-id-value">
                                {{ $identifier['value'] }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </article>
    @endforeach
</div>
