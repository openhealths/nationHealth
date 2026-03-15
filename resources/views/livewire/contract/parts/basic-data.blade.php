@if(isset($contract) && isset($data))
    <fieldset class="fieldset">
        <legend class="legend">{{ __('contracts.general_data') }}</legend>
        <div class="form-row-2">
            <div class="form-group group">
                @php
                    $typeTranslationKey = 'contracts.' . strtolower($contract->type);
                    $translatedType = __($typeTranslationKey);
                    $translatedType = $translatedType === $typeTranslationKey ? ucfirst($contract->type) : $translatedType;
                @endphp
                <input id="contract-type-id"
                       type="text"
                       value="{{ $translatedType }} • {{ $contract->uuid }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
                <label for="contract-type-id" class="label">
                    {{ __('contracts.type') }} • {{ __('contracts.id') }}
                </label>
            </div>
            @if(isset($data['parent_contract_id']))
                <div class="form-group group">
                    <input id="contract-parent"
                           type="text"
                           value="{{ $data['parent_contract_id'] }}"
                           class="input peer"
                           placeholder=" "
                           disabled
                           readonly
                    />
                    <label for="contract-parent" class="label">
                        {{ __('contracts.parent_contract') }}
                    </label>
                </div>
            @endif
        </div>
        <div class="form-row-2">
            <div class="form-group group">
                <input id="contract-created-at"
                       type="text"
                       value="{{ $contract->inserted_at?->format('d.m.Y H:i') }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
                <label for="contract-created-at" class="label">
                    {{ __('contracts.created_at_label') }}
                </label>
            </div>
            <div class="form-group group">
                <input id="contract-period"
                       type="text"
                       value="{{ \Carbon\Carbon::parse($contract->start_date)->format('d.m.Y') }} – {{ \Carbon\Carbon::parse($contract->end_date)->format('d.m.Y') }}"
                       class="input peer"
                       placeholder=" "
                       disabled
                       readonly
                />
                <label for="contract-period" class="label">
                    {{ __('contracts.period_label') }}
                </label>
            </div>
        </div>
        @if($contract->status_reason || isset($data['status_reason']))
            <div class="show-alert-warning mt-4">
                <p class="font-bold">{{ __('contracts.status_reason_label') }}</p>
                <p>{{ $contract->status_reason ?? $data['status_reason'] }}</p>
            </div>
        @endif
    </fieldset>
@else
    @php
        $dictionary = $this instanceof \App\Livewire\Contract\CapitationContractCreate ? $this->dictionaries['CONTRACT_TYPE'] : $this->dictionaries['REIMBURSEMENT_CONTRACT_TYPE'];
    @endphp
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('contracts.label') }}</h2>
        </legend>
        <p class="default-p mb-6">{{ __('contracts.contract_info') }}</p>
        <div class="form-row-2">
            <div class="form-group group">
                <select wire:model="form.idForm"
                        name="idForm"
                        id="idForm"
                        class="peer input-select @error('form.idForm') input-error @enderror"
                        required
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach($dictionary as $key => $type)
                        <option value="{{ $key }}">{{ $type }}</option>
                    @endforeach
                </select>
                <label for="idForm" class="label">
                    {{ __('forms.type') }}
                </label>
                @error('form.idForm')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
        <div class="form-row-2">
            <div class="form-group group datepicker-wrapper relative w-full">
                <input wire:model="form.startDate"
                       type="text"
                       name="startDate"
                       id="startDate"
                       class="peer input pl-10 datepicker-input @error('form.startDate') input-error @enderror"
                       placeholder=" "
                       required
                       datepicker-format="dd.mm.yyyy"
                />
                <label for="startDate" class="wrapped-label">
                    {{ __('contracts.start_date_label') }}
                </label>
                @error('form.startDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="form-group group datepicker-wrapper relative w-full">
                <input wire:model="form.endDate"
                       type="text"
                       name="endDate"
                       id="endDate"
                       class="peer input pl-10 datepicker-input @error('form.endDate') input-error @enderror"
                       placeholder=" "
                       datepicker-format="dd.mm.yyyy"
                />
                <label for="endDate" class="wrapped-label">
                    {{ __('contracts.end_date_label') }}
                </label>
                @error('form.endDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>
@endif
