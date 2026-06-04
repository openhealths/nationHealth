<div class="fragment">
    <livewire:components.x-message :key="now()->timestamp"/>

    <x-authentication-card>

        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('forms.choose_legal_entity') }}
        </h2>

        <p class="text-red-600 text-sm my-2 text-center">
            {{ __('forms.local_login') }}
        </p>

        <p class="text-red-600 text-sm mb-8 text-center">
            {{ __('forms.local_login_warning') }}
        </p>

        <form
            wire:submit.prevent="finalizeSelection"
        >
            <x-forms.combobox
                :options="$accessibleLegalEntities"
                is-required="true"
                bind="selectedLegalEntityId"
                bindValue='id'
                bindParam='name'
                :label="__('Медичний Заклад')"
                class="!z-[100] mt-6"
            />

            <div class="flex items-center justify-end mt-4">
                <button
                    type="submit"
                    id="submitButton"
                    class="login-button cursor-pointer"
                >
                    {{ __('forms.enter')  }}
                </button>
            </div>
        </form>

    </x-authentication-card>
</div>
