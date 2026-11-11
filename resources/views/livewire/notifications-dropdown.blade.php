<div
    data-total-unread-count="{{ $this->totalUnreadCount }}"
    data-verification-warning-title="{{ __('patient-verifications.warning_title') }}"
    data-verification-warning-message="{{ __('patient-verifications.warning_message') }}"
    data-just-now="{{ __('notifications.just_now') }}"
    x-data="{
        open: false,
        clientNotifications: [],

        addNotification(title, message) {
            this.clientNotifications.unshift({
                id: 'client-' + Date.now(),
                title: title,
                message: message,
                time: this.$root.dataset.justNow,
            });
            this.open = true;
        },

        removeNotification(id) {
            this.clientNotifications = this.clientNotifications.filter((notification) => notification.id !== id);
        },

        get totalCount() {
            return Number(this.$root.dataset.totalUnreadCount) + this.clientNotifications.length;
        },
    }"
    @open-notifications.window="open = true"
    @show-patient-verification-notification.window="
        addNotification($root.dataset.verificationWarningTitle, $root.dataset.verificationWarningMessage)
    "
    class="relative"
>
    <button
        @click="open = ! open"
        x-transition
        type="button"
        aria-label="Notifications"
        class="mr-1 cursor-pointer rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:ring-4 focus:ring-gray-300 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white dark:focus:ring-gray-600"
    >
        <div class="relative">
            @icon('bell', 'w-6 h-6')
            <template x-if="totalCount > 0">
                <div
                    class="absolute -right-2 -bottom-1 inline-flex h-5 w-5 items-center justify-center rounded-full border-2 border-white bg-red-600 text-xs font-bold text-white dark:border-gray-800"
                    x-text="totalCount > 99 ? '99+' : totalCount"
                ></div>
            </template>
        </div>
    </button>

    {{-- List of notifications --}}
    <div
        x-show="open"
        x-cloak
        @click.away="open = false"
        class="notification-panel absolute right-0 z-50 mt-2.75 w-80 overflow-hidden rounded-xl bg-white shadow-lg dark:bg-gray-800"
        style="width: 320px; max-width: 320px; min-width: 320px"
    >
        <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('notifications.title') }}</h3>
            <button
                x-show="totalCount > 0"
                x-cloak
                @click="
                    clientNotifications = [];
                    $wire.markAllAsRead();
                "
                type="button"
                class="text-sm text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
            >
                {{ __('notifications.close_all') }}
            </button>
        </div>

        <div class="space-y-3 p-4">
            {{-- Dynamic / client notifications --}}
            <template x-for="item in clientNotifications" :key="item.id">
                <div class="relative rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-600 dark:bg-gray-700">
                    <div class="relative flex items-start gap-3">
                        <div class="mt-0.5 shrink-0 text-red-600">
                            @icon('alert-circle', 'w-5 h-5')
                        </div>

                        <div class="min-w-0 flex-1 overflow-hidden">
                            <h4
                                class="pr-6 text-sm leading-tight font-semibold text-gray-900 dark:text-white"
                                x-text="item.title"
                            ></h4>
                            <p
                                class="mt-1 text-xs leading-snug wrap-break-word text-gray-700 dark:text-gray-300"
                                x-text="item.message"
                            ></p>
                            <small
                                class="mt-2 block text-xs text-gray-400 dark:text-gray-500"
                                x-text="item.time"
                            ></small>
                        </div>

                        <button
                            @click="removeNotification(item.id)"
                            type="button"
                            class="absolute top-0 right-0 shrink-0 text-gray-400 transition-colors hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                            aria-label="Закрити"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Backend database notifications --}}
            @foreach ($notifications as $notification)
                @php
                    $iconType = $this->getNotificationIconType($notification);
                @endphp
                <div
                    wire:key="notification-{{ $notification->id }}"
                    class="relative rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-600 dark:bg-gray-700"
                >
                    <div class="relative flex items-start gap-3">
                        <div class="mt-0.5 shrink-0">
                            @if ($iconType === 'started')
                                @icon('refresh', 'w-5 h-5 text-blue-600')
                            @elseif ($iconType === 'completed')
                                @icon('check', 'w-5 h-5 text-green-600')
                            @elseif ($iconType === 'failed')
                                @icon('alert', 'w-5 h-5 text-red-600')
                            @else
                                @icon('alert-circle', 'w-5 h-5 text-red-600')
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 overflow-hidden">
                            @if (!empty($notification->data['title']))
                                <h4 class="pr-6 text-sm leading-tight font-semibold text-gray-900 dark:text-white">
                                    {{ $notification->data['title'] }}
                                </h4>
                                @if (!empty($notification->data['message']))
                                    <p class="mt-1 text-xs leading-snug wrap-break-word text-gray-700 dark:text-gray-300">
                                        {{ $notification->data['message'] }}
                                    </p>
                                @endif
                                <small class="mt-2 block text-xs text-gray-400 dark:text-gray-500">
                                    {{ $notification->created_at->diffForHumans() }}
                                </small>
                            @else
                                <p class="pr-6 text-sm leading-tight wrap-break-word text-gray-900 dark:text-white">
                                    {{ $notification->data['message'] ?? '' }}
                                </p>
                                <small class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $notification->created_at->diffForHumans() }}
                                </small>
                            @endif
                        </div>

                        <button
                            wire:click="markAsRead('{{ $notification->id }}')"
                            wire:loading.attr="disabled"
                            type="button"
                            class="absolute top-0 right-0 shrink-0 text-gray-400 transition-colors hover:text-gray-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-500 dark:hover:text-gray-300"
                            aria-label="Закрити"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            @endforeach

            @if ($notifications->isEmpty())
                <div
                    x-show="clientNotifications.length === 0"
                    class="px-4 py-8 text-center text-gray-400 dark:text-gray-500"
                    x-cloak
                >
                    {{ __('forms.empty') }}
                </div>
            @endif
        </div>

        {{-- Link to all notifications --}}
        @if ($notifications->count() > 0)
            <a
                href="{{ Route::has('notifications.index') ? route('notifications.index') : '#' }}"
                class="block border-t border-gray-200 px-4.5 py-2.5 text-left text-sm text-blue-600 hover:text-blue-800 hover:underline dark:border-gray-700 dark:text-blue-400 dark:hover:text-blue-300"
            >
                {{ __('notifications.go_to_notifications') }}
            </a>
        @endif
    </div>
</div>
