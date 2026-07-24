@php
    use App\Livewire\Division\HealthcareService\{HealthcareServiceCreate, HealthcareServiceView, HealthcareServiceEdit};
    use App\Models\{HealthcareService, LegalEntity};

    $healthcareServiceModel = HealthcareService::find($healthcareServiceId);
@endphp

<section class="section-form">
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('healthcare-services.add') }}
        </x-slot>
    </x-header-navigation>

    <div
        class="form shift-content mt-6"
        wire:key="{{ time() }}"
    >
        <div
            x-data="{
                isDisabled: $wire.entangle('isDisabled'),
                categoryCode: $wire.entangle('form.category.coding.0.code'),
                specialityType: $wire.entangle('form.specialityType'),
                providingCondition: $wire.entangle('form.providingCondition'),
                typeCode: $wire.entangle('form.type.coding.0.code'),
                licenseId: $wire.entangle('form.licenseId'),
                requiredCategories: @js($this->categoryRequiredFields),
                isRequired(field) {
                    return this.requiredCategories && this.requiredCategories[field] ? this.requiredCategories[field].includes(this.categoryCode) : false;
                },
                init() {
                    this.$watch('categoryCode', () => {
                        if (!this.isRequired('speciality')) {
                            this.specialityType = '';
                        }

                        if (!this.isRequired('providingCondition')) {
                            this.providingCondition = '';
                        }

                        if (!this.isRequired('type')) {
                            this.typeCode = '';
                        }

                        if (!this.isRequired('license')) {
                            this.licenseId = null;
                        }
                    });
                }
            }"
        >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div
                    x-data="{
                        openDiv: false,
                        divSearch: '',
                        selectedDivs: []
                    }"
                    @click.outside="openDiv = false"
                    class="form-group group relative"
                    :style="openDiv ? 'z-index: 99999; position: relative;' : ''"
                >
                    <div
                        @click="openDiv = !openDiv"
                        class="cursor-pointer"
                    >
                        <select
                            name="divisionName"
                            id="divisionName"
                            class="input-select pointer-events-none"
                        >
                            <option selected>
                                {{ $divisionName ?? '' }}
                            </option>
                        </select>
                        <label for="divisionName" class="label">
                            {{ __('forms.division') }}*
                        </label>
                    </div>

                    <div
                        x-show="openDiv"
                        x-cloak
                        style="z-index: 99999; background-color: #ffffff;"
                        class="absolute left-0 top-full mt-2 w-72 rounded-xl border border-gray-200 dark:border-gray-700 shadow-2xl p-4 dark:bg-gray-800"
                    >
                        <div class="relative mb-3">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none z-10">
                                @icon('search', 'w-4 h-4 text-blue-500')
                            </div>
                            <input
                                type="text"
                                x-model="divSearch"
                                placeholder="{{ __('forms.search') }}"
                                style="padding-left: 2.25rem !important;"
                                class="w-full pr-3 py-1.5 text-xs rounded-lg border border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800"
                                @click.stop
                            />
                        </div>

                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                            @foreach($divisions ?? [] as $div)
                                <label class="flex items-center gap-2.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 p-1.5 rounded cursor-pointer">
                                    <input
                                        type="checkbox"
                                        value="{{ $div['id'] }}"
                                        x-model="selectedDivs"
                                        class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500"
                                    />
                                    <span>
                                        {{ __('healthcare-services.for_division', ['name' => $div['name']]) }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="form-group group">
                    <select
                        wire:model="form.category.coding.0.code"
                        name="category"
                        id="category"
                        class="input-select"
                        x-bind:disabled="isDisabled"
                    >
                        <option value="" selected>
                            {{ __('forms.select') }}
                        </option>
                        @foreach($this->dictionaries['HEALTHCARE_SERVICE_CATEGORIES'] ?? [] as $key => $category)
                            <option value="{{ $key }}">
                                {{ $category }}
                            </option>
                        @endforeach
                    </select>

                    <label for="category" class="label">
                        {{ __('healthcare-services.category') }}*
                    </label>
                </div>

                <div class="form-group group">
                    <select
                        wire:model="form.specialityType"
                        name="specialityType"
                        id="specialityType"
                        class="input-select"
                        x-bind:disabled="isDisabled"
                    >
                        <option value="" selected>
                            {{ __('forms.select') }}
                        </option>
                        @foreach($this->dictionaries['SPECIALITY_TYPE'] ?? [] as $key => $type)
                            <option value="{{ $key }}">
                                {{ $type }}
                            </option>
                        @endforeach
                    </select>

                    <label for="specialityType" class="label">
                        {{ __('healthcare-services.type') }}*
                    </label>
                </div>

                <div class="form-group group">
                    <select
                        wire:model="form.licenseId"
                        name="licenseId"
                        id="licenseId"
                        class="input-select"
                        x-bind:disabled="isDisabled"
                    >
                        <option value="" selected>
                            {{ __('forms.select') }}
                        </option>
                        @foreach($licenses ?? [] as $key => $license)
                            <option value="{{ $license['uuid'] }}">
                                {{ $license['type']->label() }}
                            </option>
                        @endforeach
                    </select>

                    <label for="licenseId" class="label">
                        {{ __('healthcare-services.license') }}*
                    </label>
                </div>
            </div>
        </div>

        <div class="flex justify-start gap-4 mt-8">
            <a
                href="{{ route('healthcare-service.index', [legalEntity()]) }}"
                class="border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 px-6 py-2.5 rounded-lg text-sm font-medium transition-colors"
            >
                {{ __('forms.cancel') }}
            </a>

            <button
                wire:click="createLocally"
                type="button"
                class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-lg text-sm font-medium shadow-sm transition-colors"
            >
                {{ __('forms.create') }}
            </button>
        </div>
    </div>

    <x-forms.loading />
    <livewire:components.x-message :key="now()->timestamp" />
</section>
