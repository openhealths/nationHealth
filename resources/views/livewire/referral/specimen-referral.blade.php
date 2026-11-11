<div class="mt-6 space-y-4">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <h2 class="text-lg font-semibold">
        {{ __('referrals.specimen_referrals') }}
    </h2>
    <button type="button" wire:click="loadReferrals" wire:loading.attr="disabled" class="button-minor">
        {{ __('referrals.specimen_load') }}
    </button>
    @foreach ($rows as $row)
        <p wire:key="specimen-referral-{{ $row['id'] }}">
            {{ $row['requisition'] }} · {{ $row['service'] }} · {{ $row['status'] }}
        </p>
    @endforeach
    @if ($this->authorChoices !== [])
        <form wire:submit="saveDraft" class="grid gap-4 md:grid-cols-2">
            <label>
                {{ __('referrals.executor') }}
                <select wire:model="executorId" class="input w-full">
                    <option value="0">
                        {{ __('forms.select') }}
                    </option>
                    @foreach ($this->authorChoices as $author)
                        <option value="{{ $author['id'] }}">
                            {{ $author['label'] }}
                        </option>
                    @endforeach
                </select>
                @error('executorId')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <label>
                {{ __('encounters.service') }}
                <select wire:model="serviceId" class="input w-full">
                    <option value="">
                        {{ __('forms.select') }}
                    </option>
                    @foreach ($this->serviceChoices as $service)
                        <option value="{{ $service['id'] }}">
                            {{ $service['code'] }} · {{ $service['name'] }}
                        </option>
                    @endforeach
                </select>
                @error('serviceId')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <label>
                {{ __('referrals.table.program') }}
                <select wire:model="programId" class="input w-full">
                    <option value="">
                        {{ __('referrals.registry.program_not_specified') }}
                    </option>
                    @foreach ($this->programChoices as $program)
                        <option value="{{ $program['id'] }}">
                            {{ $program['name'] }}
                        </option>
                    @endforeach
                </select>
                @error('programId')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <label>
                {{ __('forms.start_date') }}
                <input wire:model="startedAt" type="datetime-local" class="input w-full" />
                @error('startedAt')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <label>
                {{ __('forms.end_date') }}
                <input wire:model="endedAt" type="datetime-local" class="input w-full" />
                @error('endedAt')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <label>
                {{ __('forms.quantity') }}
                <input
                    wire:model="quantity"
                    type="number"
                    min="0.01"
                    step="0.01"
                    class="input w-full"
                />
                @error('quantity')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <label class="md:col-span-2">
                {{ __('referrals.registry.note') }}
                <textarea wire:model="note" class="input w-full"></textarea>
                @error('note')
                    <span class="text-red-600">
                        {{ $message }}
                    </span>
                @enderror
            </label>
            <div class="flex flex-wrap gap-3 md:col-span-2">
                <button type="submit" wire:loading.attr="disabled" class="button-minor">
                    {{ __('forms.save') }}
                </button>
                <button type="button" wire:click="prepareSigning" wire:loading.attr="disabled" class="button-primary">
                    {{ __('referrals.specimen_create') }}
                </button>
                @if ($draftUuid !== null)
                    <button type="button" wire:click="checkStatus" wire:loading.attr="disabled" class="button-minor">
                        {{ __('referrals.submission_check') }}
                    </button>
                @endif
            </div>
        </form>
    @endif
    <x-signature-modal method="sign" />
</div>
