@php
    natcasesort($dictionaries['STREET_TYPE']);

    $divisionView = isset($divisionView) && $divisionView === true;
    $addressType = $divisionView ? dictionary()->basics()->byName('ADDRESS_TYPE')->where('code', $address['type'] ?? '')->value('description') : null;
    $addressCountry = $divisionView ? dictionary()->basics()->byName('COUNTRY')->where('code', $address['country'] ?? '')->value('description') : null;
@endphp

<div
    x-data="{
        searchStartLength: 2,
        entangledAddress: $wire.entangle('{{ $property }}'),
        {{-- Livewire refreshes the data before it removes the element, so an address that is being deleted
             evaluates its expressions once more when it is already gone --}}
        get address() {
            return this.entangledAddress ?? {};
        },
        readonly: {{ $readonly ? 'true' : 'false' }},
        divisionView: {{ $divisionView ? 'true' : 'false' }},
        selecting: false,
        clearStreet() {
            this.address.building = '';
            this.address.apartment = '';
            this.address.zip = '';
        },
        clearSettlement() {
            this.address.streetType = '';
            this.address.street = '';
            this.clearStreet();
        },
        clearRegion() {
            this.address.settlementType = '';
            this.address.settlement = '';
            this.address.settlementId = '';
            this.clearSettlement();
        },
        clearArea() {
            this.address.region = '';
            this.clearRegion();
        },
        init() {
            this.$watch('address.area', value => {
                this.clearArea();
            });
            this.$watch('address.region', value => {
                if (! this.selecting) {
                    return;
                }

                this.clearRegion();
            });
            this.$watch('address.settlement', value => {
                if (this.address.area === 'М.КИЇВ') {
                    this.address.settlementType = 'CITY';
                    this.address.settlement = 'Київ';
                    this.address.settlementId = 'adaa4abf-f530-461c-bcbf-a0ac210d955b';

                    return;
                }

                if (! this.selecting) {
                    return;
                }

                this.clearSettlement();
            });
            this.$watch('address.street', value => {
                if (! this.selecting) {
                    return;
                }

                this.clearStreet();
            });
        }
    }"
    x-init="init()"
    class="{{ $class }}"
>
    @if($divisionView)
         {{-- COUNTRY --}}
        <div class="form-group group">
            <input
                required
                type="text"
                placeholder=" "
                id="addressCountry"
                class="input peer"
                value="{{ $addressCountry ?? '-' }}"
                disabled
            />

            <label for="addressCountry" class="label z-10">
                {{ __('forms.country') }}
            </label>
        </div>

        {{-- ADDRESS TYPE --}}
        <div class="form-group group">
            <input
                type="text"
                placeholder=" "
                id="addressType"
                class="input peer"
                value="{{ $addressType ?? '-' }}"
                disabled
            />

            <label for="addressType" class="label z-10">
                {{ __('forms.address_type') }}
            </label>
        </div>

        {{-- SETTLEMENT ID --}}
        <div class="form-group group">
            <input
                x-model="address.settlementId"
                type="text"
                placeholder=" "
                id="addressSettlementId"
                value="{{ $address['settlementId'] ?? '-' }}"
                class="input peer"
                disabled
            />

            <label for="addressSettlementId" class="label z-10">
                {{ __('forms.settlement_id') }}
            </label>
        </div>
    @endif

    {{-- AREA --}}
    <div class="form-group group !z-[28]">
        <select
            x-model.live="address.area"
            required
            id="addressArea{{ $uid }}"
            @blur="selecting = false"
            @change="address.settlement = null"
            {{-- This need to properly set a Kyiv area --}}
            aria-describedby="@error($property . '.area') addressAreaErrorHelp{{ $uid }} @enderror"
            class="input-select text-gray-800 @error($property . '.area') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="readonly"
        >
            <option value="_placeholder_" hidden>-- {{ __('forms.select') }} --</option>

            @forelse ($regions as $regionItem)
                <option value="{{ $regionItem['name'] }}">{{ $regionItem['name'] }}</option>
            @empty
            @endforelse
        </select>

        @error($property . '.area')
            <p id="addressAreaErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressArea{{ $uid }}" class="label z-10"> {{ __('forms.area') }} </label>
    </div>

    {{-- REGION --}}
    <div
        class="form-group group !z-[27]"
        {{-- @mouseleave="timeout = setTimeout(() => { showTo = false }, 800)" --}}
        x-data="{
            showTo: false,
            districtsState: $wire.entangle('districts{{ $suggestionsSuffix }}'),
            {{-- The slot of an address that was just added holds nothing yet --}}
            get districts() {
                return this.districtsState ?? [];
            },
            set districts(value) {
                this.districtsState = value;
            },
            initialized: false,
            init() {
                // tracking changes of region, but skip first time
                this.$watch('address.region', value => {
                    if (! this.initialized) {
                        this.initialized = true;

                        return; // do nothing at first time
                    }

                    if (this.selecting || address.area === 'М.КИЇВ') return;

                    if (! value || value.length < searchStartLength) {
                        this.showTo = false;
                        return;
                    }

                    $wire.call('updateRegion', '{{ $property }}', 'districts{{ $suggestionsSuffix }}', value).then(() => this.showTo = true);
                });

                // when Livewire returned districts — decide to show dropdown or not
                this.$watch('districts', value => {
                    if (this.selecting) {
                        return;
                    }

                    this.showTo = Array.isArray(value) && value.length > 0;
                });
            }
        }"
        x-init="init()"
    >
        <input
            x-model.debounce.400ms="address.region"
            @keydown.escape="showTo = false"
            @change="showTo = false"
            @blur="
                selecting = false;
                districts = [];
            "
            type="text"
            placeholder=" "
            id="addressRegion{{ $uid }}"
            autocomplete="off"
            aria-describedby="@error($property . '.region') addressRegionErrorHelp{{ $uid }} @enderror"
            class="input @error($property . '.region') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="! address.area || address.area === 'М.КИЇВ' || readonly"
        />

        <div x-show="showTo" x-cloak>
            <div
                x-on:click.away="showTo = false"
                x-transition
                class="absolute top-full right-0 left-0 rounded-br-md rounded-bl-md border border-gray-300 bg-white shadow-lg dark:border-gray-500 dark:bg-gray-800"
            >
                <ul class="py-2 text-sm text-gray-700 dark:text-gray-200" aria-labelledby="dropdownHoverButton">
                    <template x-for="district in districts" :key="district.id">
                        <li
                            x-on:mousedown.stop="
                                selecting = true;
                                showTo = false;

                                address.region = district.name.replace(/'/g, '\'');
                            "
                            class="cursor-pointer px-4 py-2 hover:bg-gray-100 dark:hover:bg-blue-800 dark:hover:text-gray-200"
                        >
                            <span x-text="district.name"></span>
                        </li>
                    </template>

                    <div x-show="! districts || (Array.isArray(districts) && districts.length === 0)" x-cloak>
                        <li class="cursor-default px-4 py-2">{{ __('forms.nothing_found') }}</li>
                    </div>
                </ul>
            </div>
        </div>

        @error($property . '.region')
            <p id="addressRegionErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressRegion{{ $uid }}" class="label z-10"> {{ __('forms.region') }} </label>
    </div>

    {{-- TYPE --}}
    <div class="form-group group !z-[26]">
        <select
            x-model="address.settlementType"
            required
            @blur="selecting = false"
            id="addressSettlementType{{ $uid }}"
            aria-describedby="@error($property . '.settlementType') addressSettlementTypeErrorHelp{{ $uid }} @enderror"
            class="input-select text-gray-800 @error($property . '.settlementType') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="! address.area || readonly"
        >
            <option value="_placeholder_" selected hidden>-- {{ __('forms.select') }} --</option>

            @isset($dictionaries['SETTLEMENT_TYPE'])
                @foreach ($dictionaries['SETTLEMENT_TYPE'] as $key => $type)
                    <option
                        class="normal-case"
                        {{ isset($address['settlementType']) && $address['settlementType'] === $key ? 'selected': '' }}
                        value="{{ $key }}"
                    >
                        {{ $type }}
                    </option>
                @endforeach
            @endisset
        </select>

        @error($property . '.settlementType')
            <p id="addressSettlementTypeErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressSettlementType{{ $uid }}" class="label z-10"> {{ __('forms.settlement_type') }} </label>
    </div>

    {{-- SETTLEMENT --}}
    <div
        class="form-group group !z-[25] self-start"
        {{-- @mouseleave="timeout = setTimeout(() => { showTo = false }, 800)" --}}
        x-data="{
            showTo: false,
            settlementsState: $wire.entangle('settlements{{ $suggestionsSuffix }}'),
            get settlements() {
                return this.settlementsState ?? [];
            },
            set settlements(value) {
                this.settlementsState = value;
            },
            initialized: false,
            exactSearch: $wire.entangle('exactSettlementMatch'),
            init() {
                this.$watch('address.settlement', value => {
                    // tracking changes of settlement, but skip first time
                    if (! this.initialized) {
                        this.initialized = true;

                        return; // do nothing at first time
                    }

                    if (this.selecting || address.area === 'М.КИЇВ') return;

                    if (! value || value.length < searchStartLength) {
                        this.showTo = false;
                        return;
                    }

                    $wire.call('updateSettlement', '{{ $property }}', 'settlements{{ $suggestionsSuffix }}', value).then(() =>  this.showTo = true);
                });

                // when Livewire returned settlements — decide to show dropdown or not
                this.$watch('settlements', value => {
                    if (this.selecting) {
                        return;
                    }

                    this.showTo = Array.isArray(value) && value.length > 0;
                });
            }
        }"
        x-init="init()"
    >
        <div class="relative">
            <input
                x-model.debounce.400ms="address.settlement"
                @keydown.escape="showTo = false"
                @change="
                    showTo = false;
                    settlements = [];
                "
                @blur="selecting = false"
                required
                type="text"
                placeholder=" "
                id="addressSettlement{{ $uid }}"
                autocomplete="off"
                aria-describedby="@error($property . '.settlement') addressSettlementErrorHelp{{ $uid }} @enderror"
                class="input @error($property . '.settlement') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                :disabled="! address.settlementType || address.area === 'М.КИЇВ' || readonly"
            />

            <div x-show="showTo && address.area !== 'М.КИЇВ'" x-cloak>
                <div
                    @click.away="showTo = false"
                    x-transition
                    class="absolute top-full right-0 left-0 !z-[25] origin-top rounded-br-md rounded-bl-md border border-gray-300 bg-white shadow-lg dark:border-gray-500 dark:bg-gray-800"
                >
                    <ul class="py-2 text-sm text-gray-700 dark:text-gray-200" aria-labelledby="dropdownHoverButton">
                        <template x-for="settlement in settlements" :key="settlement.id">
                            <li
                                x-on:mousedown.stop="
                                    selecting = true;
                                    showTo = false;

                                    address.settlement = settlement.name.replace(/'/g, '\'');
                                    address.settlementId = settlement.id;
                                "
                                class="cursor-pointer px-4 py-2 hover:bg-gray-100 dark:hover:bg-blue-800 dark:hover:text-gray-200"
                            >
                                <span x-text="settlement.name"></span>
                            </li>
                        </template>

                        <div x-show="! settlements || (Array.isArray(settlements) && settlements.length === 0)" x-cloak>
                            <li class="cursor-default px-4 py-2">{{ __('forms.nothing_found') }}</li>
                        </div>
                    </ul>
                </div>
            </div>

            @error($property . '.settlement')
                <p id="addressSettlementErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
            @enderror

            <label for="addressSettlement{{ $uid }}" class="label z-10"> {{ __('forms.settlement') }} </label>
        </div>

        <div class="mt-2 flex items-center gap-2">
            <input
                type="checkbox"
                id="exactSettlementSearch{{ $uid }}"
                class="default-checkbox text-blue-500 focus:ring-blue-200"
                x-model="exactSearch"
                :checked="exactSearch"
                :disabled="! address.settlementType || address.area === 'М.КИЇВ' || readonly"
            />
            <label
                for="exactSettlementSearch{{ $uid }}"
                class="text-xs font-medium text-gray-500 dark:text-gray-300"
            >{{ __('Шукати по точному співпадінню назви') }}</label>
        </div>
    </div>

    {{-- STREET_TYPE --}}
    <div class="form-group group !z-[24]">
        <select
            x-model="address.streetType"
            id="addressStreetType{{ $uid }}"
            @blur="selecting = false"
            aria-describedby="@error($property . '.streetType') addressStreetTypeErrorHelp{{ $uid }} @enderror"
            class="input-select text-gray-800 @error($property . '.streetType') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="! address.settlement || readonly"
        >
            <option value="_placeholder_" selected hidden>-- {{ __('forms.select') }} --</option>

            @if ($dictionaries['STREET_TYPE'])
                @foreach ($dictionaries['STREET_TYPE'] as $key => $type)
                    <option
                        class="normal-case"
                        {{ isset($address['streetType']) && $address['streetType'] === $key ? 'selected': '' }}
                        value="{{ $key }}"
                    >
                        {{ $type }}
                    </option>
                @endforeach
            @endif
        </select>

        @error($property . '.streetType')
            <p id="addressStreetTypeErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressStreetType{{ $uid }}" class="label absolute z-20"> {{ __('forms.street_type') }} </label>
    </div>

    {{-- STREET --}}
    <div
        class="form-group group !z-[23] self-start"
        {{-- @mouseleave="timeout = setTimeout(() => { showTo = false }, 800)" --}}
        x-data="{
            showTo: false,
            streetsState: $wire.entangle('streets{{ $suggestionsSuffix }}'),
            get streets() {
                return this.streetsState ?? [];
            },
            set streets(value) {
                this.streetsState = value;
            },
            initialized: false,
            init() {
                this.$watch('address.street', value => {
                    // tracking changes of settlement, but skip first time
                    if (! this.initialized) {
                        this.initialized = true;

                        return; // at first time do nothing
                    }

                    // skip when selecting from dropdown
                    if (this.selecting) {
                        return;
                    }

                    if (! value || value.length < searchStartLength) {
                        this.showTo = false;
                        return;
                    }

                    $wire.call('updateStreet', '{{ $property }}', 'streets{{ $suggestionsSuffix }}', value).then(() => this.showTo = true);
                });

                // when Livewire returned streets — decide to show dropdown or not
                this.$watch('streets', value => {
                    if (this.selecting) {
                        return;
                    }

                    this.showTo = Array.isArray(value) && value.length > 0;
                });
            }
        }"
        x-init="init()"
    >
        <input
            x-model.debounce.400ms="address.street"
            @keydown.escape="showTo = false"
            @change="
                showTo = false;
                streets = [];
            "
            @blur="selecting = false"
            type="text"
            placeholder=" "
            id="addressStreet{{ $uid }}"
            autocomplete="off"
            aria-describedby="@error($property . '.street') addressStreetErrorHelp{{ $uid }} @enderror"
            class="input @error($property . '.street') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="(! address.settlementType && ! selecting) || readonly"
        />

        <div
            x-cloak
            x-show="showTo"
            @click.away="showTo = false"
            x-transition
            class="absolute top-full right-0 left-0 origin-top rounded-br-md rounded-bl-md border border-gray-300 bg-white shadow-lg dark:border-gray-500 dark:bg-gray-800"
        >
            <ul class="py-2 text-sm text-gray-700 dark:text-gray-200" aria-labelledby="dropdownHoverButton">
                <template x-for="street in streets" :key="street.id">
                    <li
                        x-on:mousedown.stop="
                            selecting = true;
                            showTo = false;
                            address.street = street.name.replace(/'/g, '\'');
                        "
                        class="cursor-pointer px-4 py-2 hover:bg-gray-100 dark:hover:bg-blue-800 dark:hover:text-gray-200"
                    >
                        <span x-text="street.name"></span>
                    </li>
                </template>

                <div x-show="! streets || (Array.isArray(streets) && streets.length === 0)" x-cloak>
                    <li class="cursor-default px-4 py-2">{{ __('forms.nothing_found') }}</li>
                </div>
            </ul>
        </div>

        @error($property . '.street')
            <p id="addressStreetErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressStreet{{ $uid }}" class="label z-10"> {{ __('forms.street') }} </label>
    </div>

    {{-- BUILDING --}}
    <div class="form-group group !z-[22]">
        <input
            x-model="address.building"
            type="text"
            placeholder=" "
            id="addressBuilding{{ $uid }}"
            aria-describedby="@error($property . '.building') addressBuildingErrorHelp{{ $uid }} @enderror"
            class="input @error($property . '.building') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="! address.street || readonly"
        />

        @error($property . '.building')
            <p id="addressBuildingErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressBuilding{{ $uid }}" class="label z-10"> {{ __('forms.building') }} </label>
    </div>

    {{-- APARTMENT --}}
    <div class="form-group group !z-[21]">
        <input
            x-model="address.apartment"
            type="text"
            placeholder=" "
            id="addressApartment{{ $uid }}"
            aria-describedby="@error($property . '.apartment') addressApartmentErrorHelp{{ $uid }} @enderror"
            class="input @error($property . '.apartment') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="! address.street || readonly"
        />

        @error($property . '.apartment')
            <p id="addressApartmentErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressApartment{{ $uid }}" class="label z-10"> {{ __('forms.apartment') }} </label>
    </div>

    {{-- ZIP --}}
    <div class="form-group group">
        <input
            x-model="address.zip"
            type="text"
            x-mask="99999"
            placeholder=" "
            id="addressZip{{ $uid }}"
            aria-describedby="@error($property . '.zip') addressZipErrorHelp{{ $uid }} @enderror"
            class="input @error($property . '.zip') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            :disabled="! address.street || readonly"
        />

        @error($property . '.zip')
            <p id="addressZipErrorHelp{{ $uid }}" class="text-error">{{ $message }}</p>
        @enderror

        <label for="addressZip{{ $uid }}" class="label z-10"> {{ __('forms.zip_code') }} </label>
    </div>
</div>
