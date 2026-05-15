@use('App\Enums\Person\AuthenticationMethod')

<div x-data="{ showMethodSelectionModal: $wire.entangle('showMethodSelectionModal') }">
    <template x-teleport="body">
        <div x-show="showMethodSelectionModal" 
             style="display: none;"
             @keydown.escape.prevent.stop="showMethodSelectionModal = false"
             role="dialog"
             aria-modal="true"
             class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            
            <div x-transition @click="showMethodSelectionModal = false" class="modal-wrapper">
                <div @click.stop
                     x-trap.noscroll.inert="showMethodSelectionModal"
                     class="modal-content w-full max-w-xl bg-white dark:bg-gray-800 rounded-xl shadow-2xl overflow-hidden"
                >
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            {{ __('forms.auth_method_selection') ?? 'Вибір методу автентифікації' }}
                        </h3>
                        <button @click="showMethodSelectionModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            @icon('x', 'w-6 h-6')
                        </button>
                    </div>

                    <div class="p-8">
                        <p class="text-gray-600 dark:text-gray-400 mb-8 text-center">
                            {{ __('forms.select_auth_method_to_continue') ?? 'Оберіть метод автентифікації для підтвердження плану лікування пацієнтом.' }}
                        </p>

                        <div class="grid grid-cols-1 gap-4">
                            @forelse($authMethods as $authMethod)
                                @php
                                    $methodType = AuthenticationMethod::tryFrom($authMethod['type']);
                                    $isOtp = $methodType === AuthenticationMethod::OTP;
                                @endphp
                                <button wire:click="selectAuthMethod('{{ $authMethod['id'] ?? $authMethod['uuid'] }}')"
                                        class="group relative flex items-center p-4 border-2 border-gray-100 dark:border-gray-700 rounded-xl hover:border-blue-500 dark:hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all duration-200 text-left">
                                    <div class="flex-shrink-0 w-12 h-12 flex items-center justify-center rounded-full {{ $isOtp ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' }} group-hover:scale-110 transition-transform">
                                        @if($isOtp)
                                            @icon('message-square', 'w-6 h-6')
                                        @else
                                            @icon('user-check', 'w-6 h-6')
                                        @endif
                                    </div>
                                    <div class="ml-4 flex-1">
                                        <div class="text-lg font-bold text-gray-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400">
                                            {{ $methodType?->label() ?? $authMethod['type'] }}
                                        </div>
                                        @if(!empty($authMethod['phone_number']))
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ $authMethod['phone_number'] }}
                                            </div>
                                        @endif
                                    </div>
                                    <div class="ml-auto opacity-0 group-hover:opacity-100 transition-opacity">
                                        @icon('chevron-right', 'w-5 h-5 text-blue-500')
                                    </div>
                                </button>
                            @empty
                                <div class="text-center py-8">
                                    <div class="mb-4 flex justify-center">
                                        @icon('alert-circle', 'w-12 h-12 text-red-500')
                                    </div>
                                    <p class="text-red-600 font-medium mb-4">{{ __('forms.patient_has_no_auth_methods') }}</p>
                                    <a href="{{ route('persons.patient-data', [legalEntity(), $personId]) }}" 
                                       class="button-primary inline-flex items-center gap-2">
                                        @icon('plus', 'w-4 h-4')
                                        {{ __('forms.new_auth_method') }}
                                    </a>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div class="p-6 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-100 dark:border-gray-700 flex justify-end">
                        <button @click="showMethodSelectionModal = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
