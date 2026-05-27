<div class="relative"> {{-- This required for table overflow scrolling --}}
    <fieldset class="fieldset"
              x-data="{
                  openEvidenceDrawer: false,
                  selectedType: 'condition',
                  selectedEpisodeId: '{{ $episodes[0]['uuid'] ?? '' }}',
                  searchQuery: '',
                  isLoading: false,
                  searchResults: [],

                  init() {
                      this.$watch('selectedType', () => this.fetchRecords());
                      this.$watch('openEvidenceDrawer', (val) => {
                          if (val) {
                              this.selectedType = 'condition';
                              this.selectedEpisodeId = '{{ $episodes[0]['uuid'] ?? '' }}';
                              this.searchQuery = '';
                              this.searchResults = [];
                              this.fetchRecords();
                          }
                      });
                  },
                  fetchRecords() {
                      if (!this.selectedType) {
                          this.searchResults = [];
                          return;
                      }
                      this.isLoading = true;
                      $wire.searchConditionsOrObservations(this.selectedType)
                          .then(() => {
                              this.searchResults = JSON.parse(JSON.stringify($wire.evidenceDetails || []));
                          })
                          .finally(() => {
                              this.isLoading = false;
                          });
                  },
                  filteredRecords() {
                      return this.searchResults.filter(rec => {
                          if (this.selectedEpisodeId && rec.episodeId !== this.selectedEpisodeId) {
                              return false;
                          }
                          const dictionaryName = this.selectedType === 'condition'
                              ? 'eHealth/ICPC2/condition_codes'
                              : 'eHealth/LOINC/observation_codes';
                          const name = $wire.dictionaries[dictionaryName]?.[rec.codeCode] || '';
                          const label = (rec.codeCode + ' ' + name).toLowerCase();
                          if (this.searchQuery) {
                              const query = this.searchQuery.toLowerCase();
                              return label.includes(query);
                          }
                          return true;
                      });
                  },
                  addEvidence(record) {
                      const existingIds = modalCondition.evidenceDetails.map(detail => detail.id);
                      if (!existingIds.includes(record.id)) {
                          modalCondition.evidenceDetails.push({
                              id: record.id,
                              ehealthInsertedAt: record.ehealthInsertedAt,
                              codeCode: record.codeCode,
                              type: this.selectedType
                          });
                      }
                      this.openEvidenceDrawer = false;
                  }
              }"
    >
        <legend class="legend">
            <h2>{{ __('patients.evidence') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(detail, index) in modalCondition.evidenceDetails">
                <tr>
                    <td class="td-input"
                        x-text="detail.ehealthInsertedAt || ''"
                    ></td>
                    <td class="td-input"
                        x-text="`${ detail.codeCode } - ${
                            $wire.dictionaries['eHealth/LOINC/observation_codes'][detail.codeCode] ||
                            $wire.dictionaries['eHealth/ICF/classifiers'][detail.codeCode] ||
                            $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][detail.codeCode] ||
                            $wire.dictionaries['eHealth/ICPC2/condition_codes'][detail.codeCode]
                        }`"
                    ></td>
                    <td class="td-input">
                        {{-- That all that is needed for the dropdown --}}
                        <div x-data="{
                                 openDropdown: false,
                                 toggle() {
                                     if (this.openDropdown) {
                                         return this.close();
                                     }

                                     this.$refs.button.focus();

                                     this.openDropdown = true;
                                 },
                                 close(focusAfter) {
                                     if (!this.openDropdown) return;

                                     this.openDropdown = false;

                                     focusAfter && focusAfter.focus()
                                 }
                             }"
                             @keydown.escape.prevent.stop="close($refs.button)"
                             @focusin.window="!$refs.panel.contains($event.target) && close()"
                             x-id="['dropdown-button']"
                             class="relative"
                        >
                            {{-- Dropdown Button --}}
                            <button x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                            >
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200 cursor-pointer" aria-hidden="true"
                                     xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                     viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round"
                                          stroke-width="2"
                                          d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                </svg>
                            </button>

                            {{-- Dropdown Panel --}}
                            <div class="absolute" style="left: 50%"> {{-- Center a dropdown panel --}}
                                <div x-ref="panel"
                                     x-show="openDropdown"
                                     x-transition.origin.top.left
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     x-cloak
                                     class="dropdown-panel relative"
                                     style="left: -50%" {{-- Center a dropdown panel --}}
                                >
                                    <button
                                        @click.prevent="modalCondition.evidenceDetails.splice(index, 1); close($refs.button);"
                                        class="dropdown-button dropdown-delete"
                                    >
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>

        <div>
            {{-- Button to trigger the drawer --}}
            <button @click.prevent="
                          openEvidenceDrawer = true;
                      "
                    class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>

            {{-- Drawer --}}
            <x-dialog-drawer x-model="openEvidenceDrawer" maxWidth="4/5" wire:ignore>
                <x-slot name="title">
                    {{ __('patients.add_observations_reports_conditions') }}
                </x-slot>

                {{-- Search Section Header --}}
                <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100 pl-1 mt-2">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('forms.search') }}</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="form-group group">
                        <select x-model="selectedType"
                                id="drawerSelectedType"
                                class="input-select peer w-full"
                        >
                            <option value="condition">{{ __('patients.condition_or_diagnosis') }}</option>
                            <option value="observation">{{ __('patients.evidence_observations') }}</option>
                        </select>
                        <label for="drawerSelectedType" class="label">
                            {{ mb_ucfirst(__('patients.medical_records_type')) }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select x-model="selectedEpisodeId"
                                id="drawerSelectedEpisode"
                                class="input-select peer w-full"
                        >
                            <option
                                value="">{{ __('forms.select') }} {{ mb_strtolower(__('patients.episode')) }}</option>
                            @foreach($episodes as $key => $episode)
                                <option value="{{ $episode['uuid'] }}">
                                    {{ $episode['name'] }}
                                    ({{ mb_strtolower(__('patients.status.' . $episode['status'])) }})
                                    від {{ \Carbon\CarbonImmutable::parse($episode['ehealthInsertedAt'] ?? $episode['insertedAt'] ?? $episode['createdAt'] ?? now())->format('j.m.Y') }}
                                </option>
                            @endforeach
                        </select>
                        <label for="drawerSelectedEpisode" class="label">
                            {{ mb_ucfirst(__('patients.episode')) }}
                        </label>
                    </div>
                </div>

                <div class="relative">
                    <div x-show="isLoading"
                         class="absolute inset-0 flex items-center justify-center bg-white/70 dark:bg-gray-800/70 z-10"
                         x-cloak>
                        <x-forms.loading/>
                    </div>

                    <table class="table-input w-inherit">
                        <thead class="thead-input">
                        <tr>
                            <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                            <th scope="col" class="th-input">{{ __('forms.type') }}</th>
                            <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                            <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="record in filteredRecords()" :key="record.id">
                            <tr class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                                <td class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                                    x-text="record.ehealthInsertedAt || ''"></td>
                                <td class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                                    x-text="selectedType === 'condition' ? '{{ __('patients.condition_or_diagnosis') }}' : '{{ __('patients.evidence_observations') }}'"></td>
                                <td class="td-input text-[14px] text-gray-900 dark:text-white" x-text="`${ record.codeCode } - ${
                                          $wire.dictionaries[selectedType === 'condition' ? 'eHealth/ICPC2/condition_codes' : 'eHealth/LOINC/observation_codes']?.[record.codeCode] || ''
                                      }`"></td>
                                <td class="td-input text-center">
                                    <button type="button"
                                            @click="addEvidence(record)"
                                            class="inline-flex items-center justify-center text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400 font-medium text-sm transition-colors cursor-pointer"
                                    >
                                        @icon('plus', 'w-5 h-5')
                                    </button>
                                </td>
                            </tr>
                        </template>
                        </tbody>
                    </table>

                    <div x-show="!isLoading && filteredRecords().length === 0"
                         class="text-center py-8 text-gray-500 dark:text-gray-400" x-cloak>
                        {{ __('forms.nothing_found') }}
                    </div>
                </div>

                <div class="mt-6 flex justify-between space-x-2">
                    <button type="button"
                            @click="openEvidenceDrawer = false"
                            class="button-minor"
                    >
                        {{ __('forms.cancel') }}
                    </button>
                </div>
            </x-dialog-drawer>
        </div>
    </fieldset>
</div>
