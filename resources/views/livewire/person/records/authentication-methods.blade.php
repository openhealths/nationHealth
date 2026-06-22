@use('App\Enums\Person\AuthenticationMethod')

<div class="relative"> {{-- This required for table overflow scrolling --}}
    <div
        x-data="{
            openModal: false,
            modalAuthenticationMethod: new AuthenticationMethod(),
            newAuthenticationMethod: false,
            item: 0
        }"
    >
        <div class="overflow-x-auto">
            <table class="table-input w-inherit">
                <thead class="thead-input">
                    <tr>
                        <th class="th-input">{{ __('forms.type') }}</th>
                        <th class="th-input">{{ __('forms.status.label') }}</th>
                        <th class="th-input text-right">{{ __('forms.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($authenticationMethods as $index => $authenticationMethod)
                    <tr>
                        <td class="td-input">
                            {{ AuthenticationMethod::tryFrom($authenticationMethod['type'] ?? '')?->label() ?? ($authenticationMethod['type'] ?? '') }}
                        </td>
                        <td class="td-input">
                            @if(($authenticationMethod['status'] ?? 'ACTIVE') === 'ACTIVE')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                    {{ __('forms.status.active') }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $authenticationMethod['status'] ?? '' }}
                                </span>
                            @endif
                        </td>
                        <td class="td-input text-right">
                            <button type="button"
                                    class="p-1.5 text-gray-400 hover:text-red-500 dark:hover:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors cursor-pointer"
                                    title="{{ __('forms.delete') }}"
                                    wire:click.prevent="deactivateAuthMethod({{ json_encode($authenticationMethod) }})"
                            >
                                @icon('trash', 'w-5 h-5')
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div>
            <button @click.prevent="
                        openModal = true;
                        newAuthenticationMethod = true;
                        modalAuthenticationMethod = new AuthenticationMethod();
                    "
                    class="item-add my-5 cursor-pointer"
            >
                {{ __('patients.add_authentication_method') }}
            </button>
        </div>

        {{-- Modal --}}
        <template x-teleport="body"> {{-- This moves the modal at the end of the body tag --}}
            <div x-show="openModal"
                 style="display: none"
                 @keydown.escape.prevent.stop="openModal = false"
                 role="dialog"
                 aria-modal="true"
                 x-id="['modal-title']"
                 :aria-labelledby="$id('modal-title')" {{-- This associates the modal with unique ID --}}
                 class="modal"
            >

                {{-- Overlay --}}
                <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                {{-- Panel --}}
                <div x-show="openModal"
                     x-transition
                     @click="openModal = false"
                     class="relative flex min-h-screen items-center justify-center p-4"
                >
                    <div @click.stop
                         x-trap.noscroll.inert="openModal"
                         class="modal-content h-fit w-full lg:max-w-4xl"
                    >
                        {{-- Title --}}
                        <h3 class="modal-header" :id="$id('modal-title')">{{ __('forms.auth_method') }}</h3>

                        {{-- Content --}}
                        <form>
                            <div class="form-row-modal">
                                {{-- Type --}}
                                <div>
                                    <label for="authenticationMethodType" class="label-modal">
                                        {{ __('forms.type') }}
                                    </label>
                                    <select x-model="modalAuthenticationMethod.type"
                                            id="authenticationMethodType"
                                            class="input-modal"
                                            type="text"
                                            required
                                    >
                                        <option selected value="">{{ __('forms.select') }} *</option>
                                        @foreach(AuthenticationMethod::cases() as $authenticationMethodType)
                                            <option value="{{ $authenticationMethodType->value }}">
                                                {{ $authenticationMethodType->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group group !mb-0 self-end">
                                    <button class="button-primary cursor-pointer"
                                            @click.prevent="$wire.createAuthMethod(modalAuthenticationMethod).then(() => {
                                                openModal = false;
                                            })"
                                            :disabled="!modalAuthenticationMethod.type.trim()"
                                    >
                                        {{ __('forms.create') }}
                                    </button>
                                </div>
                            </div>

                            {{-- If type is OTP --}}
                            <div x-show="modalAuthenticationMethod.type === '{{ AuthenticationMethod::OTP }}'">
                                <div class="form-row-modal">
                                    <div class="form-group group">
                                        <label for="phoneNumber" class="label-modal">
                                            {{ __('forms.phone_number') }}
                                        </label>
                                        <input x-model="modalAuthenticationMethod.phoneNumber"
                                               type="tel"
                                               x-mask="+380999999999"
                                               name="phoneNumber"
                                               id="phoneNumber"
                                               class="input-modal"
                                               placeholder=" "
                                               required
                                               autocomplete="off"
                                        />
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <button type="button"
                                                wire:click.prevent="resendSms"
                                                @click="resetCooldown(); startCooldown()"
                                                x-data="{
                                                    cooldown: 60,
                                                    interval: null,
                                                    modalOpened: false,
                                                    startCooldown() {
                                                        if (this.interval) {
                                                            clearInterval(this.interval);
                                                            this.interval = null;
                                                        }

                                                        this.cooldown = 60;

                                                        if (this.cooldown > 0) {
                                                            this.interval = setInterval(() => {
                                                                if (this.cooldown > 0) {
                                                                    this.cooldown--;
                                                                } else {
                                                                    clearInterval(this.interval);
                                                                    this.interval = null;
                                                                }
                                                            }, 1000);
                                                        }
                                                    },
                                                    resetCooldown() {
                                                        this.cooldown = 60;
                                                        if (this.interval) {
                                                            clearInterval(this.interval);
                                                            this.interval = null;
                                                        }
                                                    }
                                                }"
                                                x-init=""
                                                x-effect="if (!modalOpened) { modalOpened = true; startCooldown(); }"
                                                :disabled="cooldown > 0"
                                                :class="{ 'cursor-not-allowed': cooldown > 0, 'cursor-pointer': cooldown <= 0 }"
                                                class="button-minor gap-2"
                                        >
                                            @icon('mail', 'w-4 h-4 text-gray-800 dark:text-white')
                                            <span
                                                x-text="cooldown > 0 ? `Відправити ще раз (через ${cooldown} с)` : 'Відправити ще раз'">
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- Action buttons --}}
                            <div class="mt-6 flex justify-between space-x-2">
                                <button type="button"
                                        @click="openModal = false"
                                        class="button-minor cursor-pointer"
                                >
                                    {{ __('forms.cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
    /**
     * Representation of the user's authentication method.
     */
    class AuthenticationMethod {
        type = '';
        phoneNumber = '';

        constructor(obj = null) {
            if (obj) {
                Object.assign(this, obj);
            }
        }
    }
</script>
