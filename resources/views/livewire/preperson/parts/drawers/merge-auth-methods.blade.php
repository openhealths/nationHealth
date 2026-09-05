@use('App\Enums\Person\AuthenticationMethod')

<x-dialog-drawer
    x-model="showMergeAuthDrawer"
    onCloseClick="showMergeAuthDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('patients.authentication_methods') }}
    </x-slot>

    <div class="mt-8 space-y-4">
        @forelse($authMethods as $authMethod)
            @php($type = AuthenticationMethod::from($authMethod['type']))

            <div
                wire:key="auth-method-{{ $authMethod['uuid'] }}"
                class="fieldset border border-gray-200 dark:border-gray-700 p-6 rounded-xl flex items-start justify-between gap-4 bg-gray-50/50 dark:bg-gray-800/50"
            >
                <div class="space-y-4">
                    <h4 class="text-lg text-gray-900 dark:text-white font-bold">
                        {{ $type->label() }}
                    </h4>

                    @if(!empty($authMethod['phoneNumber']))
                        <div>
                            <p class="text-sm font-medium text-gray-400 dark:text-gray-500">
                                {{ __('forms.phone_number') }}
                            </p>
                            <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mt-1">
                                {{ $authMethod['phoneNumber'] }}
                            </p>
                        </div>
                    @endif

                    @if(!empty($authMethod['alias']))
                        <div>
                            <p class="text-sm font-medium text-gray-400 dark:text-gray-500">
                                {{ __('patients.authentication_method_name') }}
                            </p>
                            <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mt-1">
                                {{ $authMethod['alias'] }}
                            </p>
                        </div>
                    @endif
                </div>

                <button
                    type="button"
                    class="button-primary"
                    @click="$wire.create('{{ $authMethod['uuid'] }}').then(() => {
                        if (Object.keys($wire.mergeRequest).length) {
                            $wire.$parent.$refresh();
                            currentMethod = '{{ $authMethod['type'] }}';
                            showMergeAuthDrawer = false;
                            showMergeConfirmationDrawer = true;
                        }
                    })"
                >
                    {{ __('forms.select') }}
                </button>
            </div>
        @empty
            <x-nothing-found class="mx-auto" maxWidth="" />
        @endforelse
    </div>

    <div class="flex gap-3 mt-8">
        <button
            class="button-minor"
            type="button"
            @click="showMergeAuthDrawer = false; showMergePatientDrawer = true"
        >
            {{ __('forms.back') }}
        </button>
    </div>
</x-dialog-drawer>
