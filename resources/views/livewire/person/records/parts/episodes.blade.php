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

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($episodes as $index => $episode)
        @php($status = Status::from(data_get($episode, 'status')))
        @php($managingOrganization = data_get($episode, 'managingOrganization.identifier.value'))
        @php($careManagerId = data_get($episode, 'careManager.identifier.value'))
        @php($managesCareManager = ($careManagerId === null || in_array($careManagerId, $manageableCareManagers, true)))

        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('forms.name') }}</div>
                    <div class="record-inner-value text-[16px]">{{ data_get($episode, 'name', '-') }}</div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        <span @class([$status->color()])> {{ $status->label() }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <div class="relative flex justify-center">
                        <div
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
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            <button
                                @click="toggle()"
                                x-ref="button"
                                :aria-expanded="open"
                                :aria-controls="$id('dropdown-button')"
                                type="button"
                                class="record-inner-action-btn cursor-pointer"
                            >
                                @icon('edit-user-outline', 'w-5 h-5')
                            </button>

                            <div
                                x-show="open"
                                x-cloak
                                x-ref="panel"
                                x-transition.origin.top.right
                                @click.outside="close($refs.button)"
                                :id="$id('dropdown-button')"
                                class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-md dark:border-gray-600 dark:bg-gray-700"
                            >
                                @if (data_get($episode, 'id'))
                                    <a
                                        href="{{ route($prepersonId !== null ? 'prepersons.episodes.view' : 'persons.episodes.view', [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'episode' => data_get($episode, 'id')]) }}"
                                        wire:navigate
                                        @click="close($refs.button)"
                                        class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                    >
                                        @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                        {{ __('patients.view_details') }}
                                    </a>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openEpisode('{{ data_get($episode, 'uuid') }}')"
                                        @click="close($refs.button)"
                                        class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                    >
                                        @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                        {{ __('patients.view_details') }}
                                    </button>
                                @endif

                                @if (in_array($status, [Status::DRAFT, Status::ACTIVE], true)
                                                                                                                                                                                                    && $managesCareManager)
                                    @if (data_get($episode, 'id'))
                                        <a
                                            href="{{ route($prepersonId !== null ? 'prepersons.episodes.edit' : 'persons.episodes.edit', [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'episode' => data_get($episode, 'id')]) }}"
                                            wire:navigate
                                            @click="close($refs.button)"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                            {{ __('forms.edit') }}
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="openEpisode('{{ data_get($episode, 'uuid') }}', true)"
                                            @click="close($refs.button)"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                            {{ __('forms.edit') }}
                                        </button>
                                    @endif
                                @endif

                                @if ($showStatusActions && $status === Status::ACTIVE && $managesCareManager)
                                    <button
                                        type="button"
                                        wire:click="openEpisodeClosing('{{ data_get($episode, 'uuid') }}')"
                                        @click="close($refs.button)"
                                        class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                    >
                                        @icon('close', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                        {{ __('forms.close') }}
                                    </button>
                                @endif

                                @if ($showStatusActions && in_array($status, [Status::ACTIVE, Status::CLOSED], true) && $managesCareManager)
                                    <button
                                        type="button"
                                        wire:click="openEpisodeCancellation('{{ data_get($episode, 'uuid') }}')"
                                        @click="close($refs.button)"
                                        class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                    >
                                        @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                        {{ __('episodes.status.entered_in_error') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="record-inner-label">{{ __('patients.date_opened') }}</div>
                            <div class="record-inner-value">{{ data_get($episode, 'period.start', '-') }}</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.date_closed') }}</div>
                            <div class="record-inner-value">{{ data_get($episode, 'period.end') ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.date_updated') }}</div>
                            <div class="record-inner-value">{{ data_get($episode, 'ehealthUpdatedAt', '-') }}</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($episode, 'careManager.displayValue') ?? '-' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('patients.filter_code') }}</div>
                        <div class="record-inner-id-value">{{ data_get($episode, 'uuid', '-') }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($episodes) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('patients.show_more') }}</button>
        </div>
    @endif
</div>
