@extends('livewire.auth.login-layout')

@section('showPassword')
    <div class="block mt-4">
        <div
            x-cloak
            x-show="!isSingleRoleAuth"
            class="form-group group"
        >
            <input
                x-model="isLocalAuth"
                type="checkbox"
                id="is_local_auth"
                class="default-checkbox text-blue-500 focus:ring-blue-300"
                :checked="isLocalAuth"
            >

            <label for="is_local_auth" class="ms-2 text-xs font-medium text-gray-500 dark:text-gray-300">
                {{ __('auth.login.register_legal_entity') }}
            </label>
        </div>

        <div
            x-cloak
            x-show="!isLocalAuth"
            class="form-group group mt-2"
        >
            <input
                x-model="isSingleRoleAuth"
                :checked="isSingleRoleAuth"
                type="checkbox"
                id="is_single_role_auth"
                class="default-checkbox text-blue-500 focus:ring-blue-300"
            >

            <label for="is_single_role_auth" class="ms-2 text-xs font-medium text-gray-500 dark:text-gray-300">
                {{ __('auth.login.single_role_auth') }}
            </label>
        </div>
    </div>
@endsection
