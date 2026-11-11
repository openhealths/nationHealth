@use('App\Enums\Person\AuthStep')

<div
    x-show="showReferralsDrawer"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-cloak
    @click="showReferralsDrawer = false; selectedDataType = ''; selectedReferralOrganization = ''; selectedReferral = '';"
    class="fixed inset-0 bg-gray-900/50"
    style="z-index: 55"
></div>

<div
    x-show="showReferralsDrawer"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="translate-x-full"
    x-cloak
    class="fixed top-0 right-0 h-screen w-4/5 overflow-y-auto bg-white p-4 pt-20 shadow-2xl transition-transform dark:bg-gray-800"
    style="z-index: 55"
    id="data-access-referrals-drawer"
    tabindex="-1"
>
    <h3 class="modal-header">
        {{ __('patients.get_data_access') }}
    </h3>

    <div class="mt-8 max-w-md">
        <div class="form-group group">
            <select class="input-select peer w-full pointer-events-none">
                <option value="referrals" selected>{{ __('patients.data_access_types.referrals') }}</option>
            </select>
            <label class="label">{{ __('patients.data_type') }}</label>
        </div>

        <div class="mt-8 form-group group">
            <select class="input-select peer w-full" x-model="selectedReferralOrganization">
                <option value="" disabled selected>{{ __('forms.select') }}</option>
                <option value="1">ТОВ "Комунальна лікарня швидкої допомоги №1"</option>
            </select>
            <label class="label">{{ __('patients.organization') }}</label>
        </div>

        <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800" x-show="selectedReferralOrganization === '1'" x-cloak>
            <p class="text-sm font-medium text-gray-900 dark:text-white">ТОВ "Комунальна лікарня швидкої допомоги №1"</p>
            <p class="text-xs text-gray-500 mt-1">Харків, 03201, вул. Гагаріна 1</p>
        </div>

        <div class="mt-8 form-group group">
            <select class="input-select peer w-full" x-model="selectedReferral">
                <option value="" disabled selected>{{ __('forms.select') }}</option>
                <option value="1">ТОВ "Комунальна лікарня швидкої допомоги №1"</option>
            </select>
            <label class="label">{{ __('patients.referral') }}</label>
        </div>

        <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800" x-show="selectedReferral === '1'" x-cloak>
            <p class="text-sm font-medium text-gray-900 dark:text-white">ТОВ "Комунальна лікарня швидкої допомоги №1"</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('patients.electronic_referral') }}</p>
            <p class="text-xs text-gray-500">29.08.2023, 7198-0713-2639-9181</p>
            <p class="text-xs text-gray-700 mt-2"><span class="font-semibold">{{ __('patients.status_label') }}:</span> Активний</p>
            <p class="text-xs text-gray-700"><span class="font-semibold">{{ __('patients.state') }}:</span> Новий</p>
            <p class="text-xs text-gray-700"><span class="font-semibold">{{ __('patients.category') }}:</span> Терапевтична взаємодія</p>
            <p class="text-xs text-gray-700"><span class="font-semibold">{{ __('patients.service') }}:</span> Z34002 - пологи; пологи; Амбулаторна/...</p>
        </div>

        <div class="mt-8 flex gap-3">
            <button class="button-minor" type="button" @click="showReferralsDrawer = false; selectedDataType = ''; selectedReferralOrganization = ''; selectedReferral = '';">
                {{ __('forms.cancel') }}
            </button>
            <button class="button-primary" type="button" @click="showReferralsDrawer = false; selectedDataType = ''; selectedReferralOrganization = ''; selectedReferral = ''; showDataAccessDrawer = false; $wire.set('authStep', {{ AuthStep::INITIAL->value }}); $wire.set('showAuthMethodModal', true);">
                {{ __('patients.get_access') }}
            </button>
        </div>
    </div>
</div>
