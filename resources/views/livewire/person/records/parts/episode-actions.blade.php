@use('App\Enums\Episode\Status')

@php
    $status = Status::tryFrom($episode['status'] ?? '');
    $careManagerId = data_get($episode, 'careManager.identifier.value');
    $managesCareManager = $careManagerId === null || in_array($careManagerId, $manageableCareManagers, true);
    $episodeId = data_get($episode, 'id');
    $routePrefix = $prepersonId !== null ? 'prepersons.episodes.' : 'persons.episodes.';
    $parameters = [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'episode' => $episodeId];
    $hasPendingSubmission = data_get($episode, 'hasPendingSubmission', false);
@endphp

<x-patient.record-actions :href="$episodeId ? route($routePrefix.'view', $parameters) : null">
    @unless ($episodeId)
        <button
            type="button"
            wire:click="openEpisode('{{ data_get($episode, 'uuid') }}')"
            @click="close($refs.button)"
            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
        >
            @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
            {{ __('forms.view_details') }}
        </button>
    @endunless

    @if ($managesCareManager && !$hasPendingSubmission && in_array($status, [Status::DRAFT, Status::ACTIVE], true))
        @if ($episodeId)
            <a
                href="{{ route($routePrefix.'edit', $parameters) }}"
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

    @if ($showStatusActions && $status === Status::ACTIVE && $managesCareManager && !$hasPendingSubmission)
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

    @if ($showStatusActions && $managesCareManager && !$hasPendingSubmission && in_array($status, [Status::ACTIVE, Status::CLOSED], true))
        <button
            type="button"
            wire:click="openEpisodeCancellation('{{ data_get($episode, 'uuid') }}')"
            @click="close($refs.button)"
            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
        >
            @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
            {{ __('medical-events.mark_as_error') }}
        </button>
    @endif

    @if ($showStatusActions && data_get($episode, 'canDelete'))
        <button
            type="button"
            data-uuid="{{ data_get($episode, 'uuid') }}"
            @click="
                close($refs.button);
                deletingUuid = $el.dataset.uuid;
            "
            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-red-600 transition-colors hover:bg-red-50 dark:text-red-500 dark:hover:bg-gray-600"
        >
            @icon('trash', 'w-5 h-5')
            {{ __('episodes.delete_draft') }}
        </button>
    @endif
</x-patient.record-actions>
