<div>
    @if(session('error') || session('success') || session('status'))
        <div class="alert-message flex fixed top-[1.5rem] w-auto z-[99999] right-2"
            x-data="message"
            x-show="showAlertMessage"
        >
            @session('error')
                <div role="alert"
                    class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400"
                >
                    <span class="font-medium">{{ session('error') }}</span>
                </div>
            @endsession

            @session('success')
                <div role="alert"
                    class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400"
                >
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            @endsession

            @session('status')
                <x-message.successes>
                    <x-slot name="status">{{ session('status') }}</x-slot>
                </x-message.successes>
            @endsession

            <button
                type="button"
                @click="showAlertMessage= false"
                aria-label="Close"
                class="absolute -top-2 -right-1 inline-flex items-center justify-center rounded-full border border-red-300 hover:border-2 hover:border-red-400 active:border-red-600 bg-white/90 hover:bg-white drop-shadow-sm shadow-lg text-gray-600 hover:text-gray-800 w-6 h-6 cursor-pointer transition-all z-[100000]"
            >
                @icon('close', 'w-3.5 h-3.5')
            </button>
        </div>
    @endif


</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('message', () => ({
            showAlertMessage: true,
            init() {
                setTimeout(() => this.showAlertMessage = false, 30000)
            }
        }))
    });
</script>
