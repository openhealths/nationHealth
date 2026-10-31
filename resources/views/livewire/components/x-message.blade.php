<div>
    @if ($notifications !== [])
        <div
            wire:key="message-{{ $this->getId() }}-{{ $notificationId }}"
            x-data="{
                showAlertMessage: true,
                alertTimeout: null,

                init() {
                    this.alertTimeout = setTimeout(() => (this.showAlertMessage = false), 30000);
                },

                destroy() {
                    clearTimeout(this.alertTimeout);
                }
            }"
            class="alert-message fixed top-6 right-2 left-2 z-99999 flex max-w-xl flex-col sm:left-auto"
            x-show="showAlertMessage"
            x-cloak
            x-transition.opacity
        >
            @foreach ($notifications as $notification)
                <div
                    role="alert"
                    @class([
                        'mb-4 rounded-lg border p-4 pr-10 text-sm dark:bg-gray-800',
                        'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:text-red-400' => $notification['type'] === 'error',
                        'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:text-green-400' => $notification['type'] === 'success',
                        'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:text-blue-300' => $notification['type'] === 'info',
                        'border-yellow-200 bg-yellow-50 text-yellow-800 dark:border-yellow-900 dark:text-yellow-300' => $notification['type'] === 'warning',
                    ])
                >
                    @if ($notification['errors'] !== [])
                        <ul class="list-inside list-disc space-y-1">
                            @foreach ($notification['errors'] as $error)
                                <li>
                                    {{ $error }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <span class="font-medium whitespace-pre-line">
                            {{ $notification['message'] }}
                        </span>
                    @endif
                </div>
            @endforeach

            <button
                type="button"
                @click.stop="showAlertMessage = false"
                aria-label="Close"
                class="absolute -top-2 -right-1 z-[100000] inline-flex h-6 w-6 cursor-pointer items-center justify-center rounded-full border border-red-300 bg-white/90 text-gray-600 shadow-lg drop-shadow-sm transition-all hover:border-2 hover:border-red-400 hover:bg-white hover:text-gray-800 active:border-red-600"
            >
                @icon('close', 'w-3.5 h-3.5')
            </button>
        </div>
    @endif
</div>
