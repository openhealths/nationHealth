@if(isset($contract) && isset($data))
    <fieldset class="fieldset">
        <legend class="legend">
            {{ __('contracts.divisions_places') }}
            <span class="show-badge-count">{{ count(data_get($data, 'contractor_divisions', [])) }}</span>
        </legend>
        <div class="show-fieldset-table-wrapper">
            <table class="show-fieldset-table">
                <thead class="index-table-thead">
                    <tr>
                        <th class="index-table-th">{{ __('contracts.name') }}</th>
                        <th class="index-table-th">{{ __('contracts.address') }}</th>
                        <th class="index-table-th">{{ __('contracts.contacts') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(data_get($data, 'contractor_divisions', []) as $division)
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">{{ $division['name'] ?? '---' }}</td>
                            <td class="index-table-td">
                                @foreach($division['addresses'] ?? [] as $addr)
                                    <div>{{ $addr['settlement'] ?? '' }}, {{ $addr['street'] ?? '' }} {{ $addr['building'] ?? '' }}</div>
                                @endforeach
                            </td>
                            <td class="index-table-td">
                                @foreach($division['phones'] ?? [] as $phone)
                                    <div class="flex items-center gap-1">@icon('phone', 'w-3 h-3') {{ $phone['number'] }}</div>
                                @endforeach
                                @if(isset($division['email']))
                                    <div class="flex items-center gap-1 mt-1">@icon('mail', 'w-3 h-3') {{ $division['email'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="index-table-tr">
                            <td colspan="3" class="index-table-td text-center">{{ __('contracts.no_divisions_in_contract') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </fieldset>
@else
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('forms.divisions') }}</h2>
        </legend>
        <p class="default-p mb-6">{{ __('contracts.divisions_info') }}</p>
        <div x-data="{ divisions: $wire.entangle('form.contractorDivisions') }"
             x-init="if (!Array.isArray(divisions) || divisions.length === 0) { divisions = [''] }">
            <div id="division-fields-container">
                <template x-for="(divisionId, index) in divisions" :key="index">
                    <div class="form-row-3">
                        <div class="form-group group">
                            <select x-model="divisions[index]"
                                    :name="'divisionName_' + index"
                                    :id="'divisionName_' + index"
                                    class="input-select"
                                    :class="{ 'input-error': $wire.errors.has(`form.contractorDivisions.${index}`) }">
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($divisions as $division)
                                    <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                                @endforeach
                            </select>
                            <label :for="'divisionName_' + index" class="label">
                                {{ __('forms.division_name') }}
                            </label>
                            @error('form.contractorDivisions')
                                <template x-if="$wire.errors.has(`form.contractorDivisions.${index}`)">
                                    <p class="text-error" x-text="$wire.errors.get(`form.contractorDivisions.${index}`)"></p>
                                </template>
                            @enderror
                        </div>
                        <div class="flex items-center space-x-4 justify-start mt-2 md:mt-0">
                            <template x-if="divisions.length > 1">
                                <button type="button" @click.prevent="divisions.splice(index, 1)" class="text-error ml-2 cursor-pointer hover:text-red-800">
                                    @icon('delete', 'w-5 h-5 text-red-600')
                                </button>
                            </template>
                            <template x-if="index === divisions.length - 1">
                                <button type="button" @click.prevent="divisions.push('')" class="item-add" id="add-division-button-hidden">
                                    {{ __('forms.add_new_division') }}
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </fieldset>
@endif
