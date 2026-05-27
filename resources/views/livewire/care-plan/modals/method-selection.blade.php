@use('App\Enums\Person\AuthenticationMethod')

<div x-data="{ showMethodSelectionModal: $wire.entangle('showMethodSelectionModal') }">
    <template x-teleport="body">
        <div x-show="showMethodSelectionModal" 
             style="display: none"
             @keydown.escape.prevent.stop="showMethodSelectionModal = false"
             role="dialog"
             aria-modal="true"
             class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>

            <div x-transition @click="showMethodSelectionModal = false" class="modal-wrapper">
                <div @click.stop
                     x-trap.noscroll.inert="showMethodSelectionModal"
                     class="modal-content w-full max-w-4xl mx-auto"
                >
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">
                            Активація плану лікування
                        </h2>
                        <button @click="showMethodSelectionModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            @icon('close', 'w-6 h-6')
                        </button>
                    </div>

                    <p class="text-gray-600 dark:text-gray-400 mb-8">
                        Оберіть метод підтвердження плану лікування пацієнтом для його активації в ЕСОЗ.
                    </p>

                    @php
                        $patientId = $this->personId ?? ($this->carePlan?->person_id ?? ($carePlan?->person_id ?? null));
                        
                        // Group active authMethods by type
                        $activeMethods = [];
                        if (is_array($authMethods)) {
                            foreach ($authMethods as $m) {
                                $activeMethods[$m['type']] = $m;
                            }
                        }

                        $standardMethods = [
                            [
                                'type' => 'OTP',
                                'label' => 'СМС-повідомлення',
                                'description' => 'Код підтвердження надходить на телефонний номер пацієнта.',
                                'icon' => 'mail',
                            ],
                            [
                                'type' => 'OFFLINE',
                                'label' => 'Документи',
                                'description' => 'Автентифікація шляхом завантаження скан-копій документів.',
                                'icon' => 'file-text',
                            ],
                            [
                                'type' => 'THIRD_PERSON',
                                'label' => 'Довірена особа',
                                'description' => 'Підтвердження через законного представника / довірену особу.',
                                'icon' => 'users',
                            ],
                        ];
                    @endphp

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        @foreach($standardMethods as $method)
                            @php
                                $configured = $activeMethods[$method['type']] ?? null;
                                $isActive = !empty($configured);
                            @endphp
                            <div class="flex flex-col h-full border border-gray-200 dark:border-gray-700 rounded-xl p-6 bg-white dark:bg-gray-800 shadow-sm relative transition-all {{ $isActive ? 'hover:shadow-md hover:border-blue-500' : 'opacity-70 hover:opacity-90' }}">
                                {{-- Header/Icon --}}
                                <div class="flex items-center gap-4 mb-4">
                                    <div class="w-12 h-12 rounded-lg flex items-center justify-center {{ $isActive ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-gray-100 text-gray-400 dark:bg-gray-700' }}">
                                        @icon($method['icon'], 'w-6 h-6')
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900 dark:text-white leading-tight">
                                            {{ $method['label'] }}
                                        </h4>
                                        <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $isActive ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                                            {{ $isActive ? 'Активний' : 'Не налаштовано' }}
                                        </span>
                                    </div>
                                </div>

                                {{-- Description --}}
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4 flex-grow">
                                    {{ $method['description'] }}
                                </p>

                                {{-- Config details if active --}}
                                @if($isActive)
                                    @if($method['type'] === 'OTP' && !empty($configured['phone_number']))
                                        <div class="text-sm font-semibold text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-950 p-2.5 rounded-lg mb-4 text-center">
                                            {{ $configured['phone_number'] }}
                                        </div>
                                    @else
                                        <div class="h-14"></div>
                                    @endif

                                    <button wire:click="selectAuthMethod('{{ $configured['id'] ?? $configured['uuid'] }}')" type="button" class="button-primary w-full justify-center">
                                        ОБРАТИ ЦЕЙ МЕТОД
                                    </button>
                                @else
                                    <div class="h-14"></div>
                                    <a href="{{ route('persons.patient-data', [legalEntity(), $patientId]) }}" class="button-minor w-full text-center justify-center block">
                                        Налаштувати метод
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-between items-center pt-6 border-t border-gray-100 dark:border-gray-700">
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
