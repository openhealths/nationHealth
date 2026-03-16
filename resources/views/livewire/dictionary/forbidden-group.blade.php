<div>
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.forbidden_group.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4 max-w-sm">
                <div class="flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('dictionaries.forbidden_group.search_title') }}</p>
                </div>

                <div class="form-group group w-full">
                    <label for="forbiddenGroup" class="default-label mb-2">
                        {{ __('dictionaries.forbidden_group.group_label') }}
                    </label>

                    <select id="forbiddenGroup"
                            name="forbiddenGroup"
                            class="peer input-select w-full"
                            wire:model="selectedForbiddenGroup"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($forbiddenGroups as $forbiddenGroup)
                            <option value="{{ $forbiddenGroup['id'] }}">{{ $forbiddenGroup['name'] }}</option>
                        @endforeach
                    </select>

                    @error('selectedForbiddenGroup') <p class="text-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="search" class="button-primary flex items-center gap-2">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    @nonempty($forbiddenDetails)
    <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
        <fieldset class="fieldset p-6 sm:p-8">
            <legend class="legend">{{ $forbiddenDetails['name'] }}</legend>

            <div class="space-y-2 text-gray-900 dark:text-gray-100">
                @foreach($forbiddenDetails['forbidden_group_codes'] as $code)
                    <p class="text-base">
                        <span class="font-semibold">{{ $code['code'] }}</span>
                        @if(!empty($code['description']))
                            <span> - {{ $code['description'] }}</span>
                        @endif
                    </p>
                @endforeach
            </div>

            <hr class="my-2.5 border-gray-200 dark:border-gray-700">

            <div class="space-y-3 text-gray-900 dark:text-gray-100">
                <p class="font-semibold">{{ __('dictionaries.forbidden_group.services_list_title') }}</p>
                <div class="space-y-2">
                    @foreach($forbiddenDetails['forbidden_group_services'] as $service)
                        <p class="text-base">
                            <span class="font-semibold">{{ $service['id'] }}</span>
                            @if(!empty($service['description']))
                                <span> - {{ $service['description'] }}</span>
                            @endif
                        </p>
                    @endforeach
                </div>
            </div>
        </fieldset>
    </section>
    @endnonempty

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
