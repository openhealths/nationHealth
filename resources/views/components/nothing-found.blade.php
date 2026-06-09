@props([
    'title' => __('forms.nothing_found'),
    'description' => __('forms.changing_search_parameters'),
    'maxW2xl' => true,
])

<fieldset {{ $attributes->merge(['class' => 'fieldset pl-[3.5px] ml-0 mr-auto w-full max-w-full']) }}>
    <legend class="legend relative -top-5 ml-0">
        @icon('nothing-found', 'w-28 h-28')
    </legend>

    <div class="p-4 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-start mb-4 {{ $maxW2xl ? 'max-w-2xl' : '' }}">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0 mt-0.5">
                @icon('alert-circle', 'w-5 h-5 text-blue-500 dark:text-blue-400 mr-3 mt-1')
            </div>
            <div class="flex-1">
                <p class="font-bold text-blue-800 dark:text-blue-300">
                    {{ $title }}
                </p>
                @if($description)
                    <p class="text-sm text-blue-600 dark:text-blue-400">
                        {{ $description }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</fieldset>
