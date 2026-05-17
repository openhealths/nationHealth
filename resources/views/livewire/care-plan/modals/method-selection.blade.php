@use('App\Enums\Person\AuthenticationMethod')

<div x-data="{ showMethodSelectionModal: $wire.entangle('showMethodSelectionModal') }">
    <template x-teleport="body">
        <div x-show="showMethodSelectionModal" 
             style="display: none"
             @keydown.escape.prevent.stop="showMethodSelectionModal = false"
             role="dialog"
             aria-modal="true"
             class="fixed inset-0 z-[100] overflow-y-auto"
        >
            <div x-show="showMethodSelectionModal" 
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
                <div x-show="showMethodSelectionModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     @click.stop
                     class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-gray-800 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-2xl"
                >
                    <div class="px-8 pt-8 pb-4">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white">Активація плану лікування</h3>
                            <button @click="showMethodSelectionModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                                @icon('x', 'w-6 h-6')
                            </button>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 mb-8">
                            Оберіть метод підтвердження плану лікування пацієнтом для його активації в ЕСОЗ.
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            @forelse($authMethods as $authMethod)
                                @php
                                    $methodType = AuthenticationMethod::tryFrom($authMethod['type']);
                                    $isOtp = $methodType === AuthenticationMethod::OTP;
                                @endphp
                                <button wire:click="selectAuthMethod('{{ $authMethod['id'] ?? $authMethod['uuid'] }}')"
                                        class="group relative flex flex-col items-center p-8 border-2 border-gray-100 dark:border-gray-700 rounded-2xl hover:border-blue-500 dark:hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all duration-200 text-center">
                                    
                                    <div class="w-20 h-20 flex items-center justify-center rounded-full {{ $isOtp ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' }} mb-6 group-hover:scale-110 transition-transform">
                                        @if($isOtp)
                                            @icon('message-square', 'w-10 h-10')
                                        @else
                                            @icon('user-check', 'w-10 h-10')
                                        @endif
                                    </div>
                                    
                                    <div class="text-xl font-bold text-gray-900 dark:text-white mb-2">
                                        {{ $methodType?->label() ?? $authMethod['type'] }}
                                    </div>
                                    
                                    @if(!empty($authMethod['phone_number']))
                                        <div class="text-sm text-gray-500 dark:text-gray-400 font-medium">
                                            {{ $authMethod['phone_number'] }}
                                        </div>
                                    @endif

                                    <div class="mt-6 px-6 py-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm font-bold text-gray-700 dark:text-gray-200 group-hover:bg-blue-600 group-hover:text-white group-hover:border-blue-600 transition-all">
                                        ОБРАТИ ЦЕЙ МЕТОД
                                    </div>
                                </button>
                            @empty
                                <div class="col-span-2 text-center py-12 bg-red-50 dark:bg-red-900/10 rounded-2xl border-2 border-dashed border-red-200">
                                    <div class="mb-4 flex justify-center">
                                        @icon('alert-circle', 'w-16 h-16 text-red-500')
                                    </div>
                                    <p class="text-red-600 font-bold text-lg mb-4">У пацієнта не вказано методів автентифікації</p>
                                    <a href="{{ route('persons.patient-data', [legalEntity(), $this->personId ?? ($carePlan->person_id ?? $personId)]) }}" 
                                       class="button-primary inline-flex items-center gap-2">
                                        @icon('plus', 'w-4 h-4')
                                        Додати метод в дані пацієнта
                                    </a>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div class="p-8 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
                        <span class="text-xs text-gray-400 font-medium italic">Дані завантажено з ЕСОЗ</span>
                        <button @click="showMethodSelectionModal = false" class="button-minor px-8">
                            {{ __('forms.cancel') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
