<div x-data="message" @flash-message.window="handleFlash($event)" x-init="setupListeners()">
    @if(session('error') || session('success') || session('status') || session('info'))
        <div class="alert-message flex fixed top-[1.5rem] w-auto z-[99999] right-2"
            x-show="showAlertMessage"
        >
            @session('error')
                <div role="alert"
                    class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400 border border-red-200 dark:border-red-900"
                >
                    <span class="font-medium whitespace-pre-line">{{ session('error') }}</span>
                </div>
            @endsession

            @session('success')
                <div role="alert"
                    class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400 border border-green-200 dark:border-green-900"
                >
                    <span class="font-medium whitespace-pre-line">{{ session('success') }}</span>
                </div>

                @php
                    session()->forget('success');
                @endphp
            @endsession

            @session('info')
                <div role="alert"
                    class="p-4 mb-4 text-sm text-blue-800 rounded-lg bg-blue-50 dark:bg-gray-800 dark:text-blue-300 border border-blue-200 dark:border-blue-900"
                >
                    <span class="font-medium whitespace-pre-line">{{ session('info') }}</span>
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

    <div class="alert-message flex fixed top-[1.5rem] w-auto z-[99999] right-2"
        x-show="showDynamicMessage"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-[-20px]"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-[-20px]"
        style="display: none;"
    >
        <div role="alert"
            :class="{
                'text-red-800 bg-red-50 dark:bg-gray-800 dark:text-red-400 border border-red-200 dark:border-red-900': dynamicType === 'error',
                'text-green-800 bg-green-50 dark:bg-gray-800 dark:text-green-400 border border-green-200 dark:border-green-900': dynamicType === 'success',
                'text-blue-800 bg-blue-50 dark:bg-gray-800 dark:text-blue-300 border border-blue-200 dark:border-blue-900': dynamicType === 'info',
                'text-yellow-800 bg-yellow-50 dark:bg-gray-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-900': dynamicType === 'warning'
            }"
            class="p-4 mb-4 text-sm rounded-lg shadow-xl pr-10"
        >
            <span class="font-medium whitespace-pre-line" x-text="dynamicText"></span>
        </div>
        <button
            type="button"
            @click="showDynamicMessage = false"
            aria-label="Close"
            class="absolute -top-2 -right-1 inline-flex items-center justify-center rounded-full border border-red-300 hover:border-2 hover:border-red-400 active:border-red-600 bg-white/90 hover:bg-white drop-shadow-sm shadow-lg text-gray-600 hover:text-gray-800 w-6 h-6 cursor-pointer transition-all z-[100000]"
        >
            @icon('close', 'w-3.5 h-3.5')
        </button>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('message', () => ({
            showAlertMessage: {{ (session('error') || session('success') || session('status') || session('info')) ? 'true' : 'false' }},
            showDynamicMessage: false,
            dynamicText: '',
            dynamicType: 'success',
            init() {
                if (this.showAlertMessage) {
                    setTimeout(() => this.showAlertMessage = false, 30000);
                }
            },
            setupListeners() {
                let handler = (data) => {
                    let item = Array.isArray(data) ? data[0] : (data.detail ? (Array.isArray(data.detail) ? data.detail[0] : data.detail) : data);
                    if (item && (item.message || item.text)) {
                        this.showAlertMessage = false;
                        this.dynamicText = item.message || item.text;
                        this.dynamicType = item.type || 'success';
                        this.showDynamicMessage = true;
                        setTimeout(() => this.showDynamicMessage = false, 30000);
                    }
                };
                window.addEventListener('flashMessage', handler);
                window.addEventListener('flash-message', handler);
                if (typeof Livewire !== 'undefined') {
                    Livewire.on('flashMessage', handler);
                } else {
                    document.addEventListener('livewire:init', () => {
                        Livewire.on('flashMessage', handler);
                    });
                }
            },
            handleFlash(event) {
                let item = event.detail ? (Array.isArray(event.detail) ? event.detail[0] : event.detail) : event;
                if (item && (item.message || item.text)) {
                    this.showAlertMessage = false;
                    this.dynamicText = item.message || item.text;
                    this.dynamicType = item.type || 'success';
                    this.showDynamicMessage = true;
                    setTimeout(() => this.showDynamicMessage = false, 30000);
                }
            }
        }))
    });
</script>
