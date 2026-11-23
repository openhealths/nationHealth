@use('App\Enums\Episode\Status')
@use('App\Models\Employee\Employee')
@use('Illuminate\Support\Facades\Auth')

@php
    $episodes = $episodes ?? $this->episodes;
    $limit = $limit ?? null;
    $hasLimit = $limit && count($episodes) > $limit;

    // Closing and cancelling an episode is driven by the host component, only the episode list has those actions
    $showStatusActions = $showStatusActions ?? false;

    $careManagerIds = collect($episodes)
        ->map(static fn (array $episode): ?string => data_get($episode, 'careManager.identifier.value'))
        ->filter()
        ->unique();

    // The care managers the user may act through, resolved for the whole list at once
    $manageableCareManagers = Employee::manageableBy(Auth::user())
        ->whereIn('uuid', $careManagerIds)
        ->pluck('uuid')
        ->all();
@endphp

@if (isset($summaryCards))
    @include ('livewire.person.records.parts.summary-records', ['showEpisodeActions' => true])
@else
    <div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
        @foreach ($episodes as $index => $episode)
            @php($status = Status::from(data_get($episode, 'status')))

            <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox h-5 w-5" />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('episodes.name') }}</div>
                        <div class="record-inner-value text-[16px]">{{ data_get($episode, 'name', '-') }}</div>
                    </div>

                    <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                        <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                        <div>
                            <span @class([$status->color()])> {{ $status->label() }} </span>
                        </div>
                    </div>

                    <div class="record-inner-action-col">
                        @include ('livewire.person.records.parts.episode-actions')
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="record-inner-label">{{ __('episodes.period_start') }}</div>
                                <div class="record-inner-value">{{ data_get($episode, 'period.start', '-') }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('episodes.period_end') }}</div>
                                <div class="record-inner-value">{{ data_get($episode, 'period.end') ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('episodes.updated_at_date') }}</div>
                                <div class="record-inner-value">{{ data_get($episode, 'ehealthUpdatedAt', '-') }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('episodes.doctor') }}</div>
                                <div class="record-inner-value">
                                    {{ data_get($episode, 'careManager.displayValue') ?? '-' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="record-inner-id-col">
                        <div class="min-w-0">
                            <div class="record-inner-label">{{ __('episodes.ehealth_id') }}</div>
                            <div class="record-inner-id-value">{{ data_get($episode, 'uuid', '-') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        @if ($hasLimit)
            <div x-show="limit < {{ count($episodes) }}" class="mt-4 flex justify-start">
                <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
            </div>
        @endif
    </div>
@endif
