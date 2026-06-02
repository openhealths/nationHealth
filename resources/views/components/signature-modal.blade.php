@props(['method'])

<template x-teleport="body">
    <div x-data="{ showSignatureModal: $wire.entangle('showSignatureModal') }"
         x-show="showSignatureModal"
         x-cloak
         role="dialog"
         aria-modal="true"
         class="modal"
         @keydown.escape.prevent.stop="showSignatureModal = false"
    >
        <div x-transition.opacity class="fixed inset-0 bg-black/30" @click="showSignatureModal = false"></div>
        <div class="modal-wrapper">
            <div class="modal-content w-full max-w-4xl mx-auto"
                 @click.stop
                 x-transition
                 x-trap.noscroll.inert="showSignatureModal"
            >
                {{-- Title --}}
                <h3 class="modal-header">{{ __('forms.sign_with_KEP') }}</h3>

                {{-- Content --}}
                <div class="p-6">
                    <form>
                        <div class="flex flex-col gap-6">
                            @if(method_exists($this, 'getStatusReasonsProperty') && isset($this->actionType) && in_array($this->actionType, ['cancel', 'revoke', 'complete', 'cancel_activity', 'complete_activity', 'cancel_prescription', 'cancel_referral']))
                                <div>
                                    <label for="statusReason" class="default-label">{{ __('care-plan.status_reason') }} *</label>
                                    <select class="input-modal" wire:model="statusReason" name="statusReason" id="statusReason">
                                        <option value="" selected>{{__('forms.select')}}</option>
                                        @foreach($this->statusReasons as $code => $description)
                                            <option value="{{ $code }}" wire:key="reason-{{ $code }}">
                                                {{ $description }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('statusReason') <p class="text-error">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            @if(isset($this->actionType) && $this->actionType === 'complete_activity')
                                <div>
                                    <label for="outcomeCode" class="default-label">{{ __('care-plan.outcome_dictionary') }} *</label>
                                    <select class="input-modal" wire:model="outcomeCode" name="outcomeCode" id="outcomeCode">
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach($this->dictionaries['care_plan_activity_outcomes'] ?? [] as $code => $description)
                                            <option value="{{ $code }}" wire:key="outcome-{{ $code }}">
                                                {{ $description }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('outcomeCode') <p class="text-error">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="default-label">{{ __('care-plan.referral_outcome') }}</label>
                                    <div class="flex gap-2">
                                        <div class="flex-1">
                                            <select class="input-modal" wire:model="selectedOutcomeReference" id="selectedOutcomeReference">
                                                <option value="" selected>{{ __('forms.select') }}</option>
                                                @if(!empty($this->availableConditions))
                                                    <optgroup label="Діагнози/Стани">
                                                        @foreach($this->availableConditions as $item)
                                                            <option value="{{ $item['uuid'] }}" wire:key="ref-condition-{{ $item['uuid'] }}">
                                                                {{ $item['name'] }} ({{ $item['date'] }})
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                                @if(!empty($this->availableObservations))
                                                    <optgroup label="Спостереження">
                                                        @foreach($this->availableObservations as $item)
                                                            <option value="{{ $item['uuid'] }}" wire:key="ref-observation-{{ $item['uuid'] }}">
                                                                {{ $item['name'] }} ({{ $item['date'] }})
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                                @if(!empty($this->availableReports))
                                                    <optgroup label="Діагностичні звіти">
                                                        @foreach($this->availableReports as $item)
                                                            <option value="{{ $item['uuid'] }}" wire:key="ref-report-{{ $item['uuid'] }}">
                                                                {{ $item['name'] }} ({{ $item['date'] }})
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            </select>
                                        </div>
                                        <button type="button" class="button-primary shrink-0" wire:click="addOutcomeReference">
                                            Додати
                                        </button>
                                    </div>
                                    
                                    @if(!empty($this->outcomeReferences))
                                        <div class="mt-4 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                                <thead class="bg-gray-50 dark:bg-gray-800">
                                                    <tr>
                                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Тип</th>
                                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Назва</th>
                                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Дата</th>
                                                        <th class="px-4 py-2"></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                                                    @foreach($this->selectedOutcomeReferencesDetails as $ref)
                                                        <tr wire:key="selected-ref-{{ $ref['uuid'] }}">
                                                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $ref['type'] }}</td>
                                                            <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">{{ $ref['name'] }}</td>
                                                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $ref['date'] }}</td>
                                                            <td class="px-4 py-2 text-right">
                                                                <button type="button" class="text-red-500 hover:text-red-700" wire:click="removeOutcomeReference('{{ $ref['uuid'] }}')">
                                                                    @icon('delete', 'w-4 h-4')
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- KEP Provider --}}
                            <div>
                                <label for="knedp" class="default-label">{{ __('forms.knedp') }} *</label>
                                <select class="input-modal" wire:model="form.knedp" name="knedp" id="knedp">
                                    <option value="" selected>{{__('forms.select')}}</option>
                                    @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                                        <option value="{{ $certificateType['id'] }}"
                                                wire:key="{{ $certificateType['id'] }}"
                                        >
                                            {{ $certificateType['name'] }}
                                        </option>
                                    @endforeach
                                </select>

                                @error('form.knedp') <p class="text-error">{{ $message }}</p> @enderror
                            </div>

                            {{-- Key File --}}
                            <div>
                                <label for="keyContainerUpload" class="default-label">
                                    {{ __('forms.key_container_upload') }} *
                                </label>
                                <input type="file"
                                       wire:model="form.keyContainerUpload"
                                       class="default-input cursor-pointer"
                                       id="keyContainerUpload"
                                       name="keyContainerUpload"
                                       accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                >
                                <div wire:loading
                                     wire:target="form.keyContainerUpload"
                                     class="text-sm text-gray-500 mt-2"
                                >
                                    {{ __('general.loading') }}...
                                </div>

                                @error('form.keyContainerUpload') <p class="text-error">{{ $message }}</p> @enderror
                            </div>

                            {{-- Password --}}
                            <div>
                                <label for="password" class="default-label">{{ __('forms.password') }} *</label>
                                <input type="password"
                                       wire:model="form.password"
                                       class="default-input"
                                       id="password"
                                       name="password"
                                       autocomplete="current-password"
                                />

                                @error('form.password') <p class="text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" @click="showSignatureModal = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>
                    <button wire:click="{{ $method }}"
                            type="button"
                            class="button-primary"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            wire:target="{{ $method }}"
                    >
                        <span wire:loading.remove wire:target="{{ $method }}">{{ __('forms.sign') }}</span>
                        <span wire:loading wire:target="{{ $method }}">{{ __('forms.signature') }}...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
