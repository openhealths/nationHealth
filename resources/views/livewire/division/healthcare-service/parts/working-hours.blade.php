<fieldset
    class="fieldset"
    x-data="{
              working: $wire.entangle('working'),
              localAvailableTime: [],
              isDisabled: $wire.entangle('isDisabled'),
              weekdaysKeys: {{ json_encode(array_keys($weekdays)) }},
              notAvailable: [],
              init() {
                  this.notAvailable = $wire.form.notAvailable || [];

                  this.notAvailable = this.notAvailable.map(item => ({
                      ...item,
                      frontendId: item.frontendId || Date.now() + Math.random()
                  }));

                  // Create default structure for all days
                  const defaultTimes = this.weekdaysKeys.map(key => ({
                      daysOfWeek: [key],
                      working: false,
                      allDay: false,
                      availableStartTime: null,
                      availableEndTime: null
                  }));

                  // Merge existing data (for edit mode)
                  const existing = $wire.form.availableTime || [];
                  existing.forEach(ex => {
                      const day = ex.daysOfWeek[0];
                      const idx = this.weekdaysKeys.indexOf(day);
                      if (idx !== -1) {
                          defaultTimes[idx] = {
                              ...defaultTimes[idx],
                              ...ex,
                              // Cut seconds off, the timepicker works with HH:MM
                              availableStartTime: ex.availableStartTime?.slice(0, 5) ?? null,
                              availableEndTime: ex.availableEndTime?.slice(0, 5) ?? null,
                              working: true
                          };
                      }
                  });

                  this.localAvailableTime = defaultTimes;

                  // Watch and sync filtered data to Livewire (strip 'working')
                  this.$watch('localAvailableTime', (value) => {
                      $wire.form.availableTime = value.filter(item =>
                          item.working &&
                          (item.availableStartTime || item.availableEndTime || item.allDay) &&
                          item.daysOfWeek && item.daysOfWeek.length > 0
                      ).map(({ working, ...rest }) => {
                          // Add seconds if missing
                          if (rest.availableStartTime && rest.availableStartTime.length === 5) {
                              rest.availableStartTime += ':00';
                          }
                          if (rest.availableEndTime && rest.availableEndTime.length === 5) {
                              rest.availableEndTime += ':00';
                          }

                          return rest;
                      });
                  }, { deep: true });

                  // sync
                  this.$watch('notAvailable', (value) => {
                      $wire.form.notAvailable = value;
                  }, { deep: true });
              },
              addNotAvailable() {
                  this.notAvailable.push({
                      frontendId: Date.now() + Math.random(),
                      during: {
                          startDate: null,
                          startTime: null,
                          endDate: null,
                          endTime: null
                      },
                      description: null
                  });
              }
          }"
>
    <legend class="legend">{{ __('healthcare-services.available_time') }}</legend>

    <div class="form">
        <div class="form-group mb-4">
            <button
                @click.prevent="working = ! working"
                x-text="working ? '{{ __('forms.remove_work_schedule') }}' : '{{ __('forms.work_schedule') }}'"
                class="item-add"
            >
                {{ __('add_work_schedule') }}
            </button>
        </div>

        <div x-cloak x-show="working" class="mb-4 flex items-start rounded-lg bg-blue-100 p-4">
            @icon('alert-circle', 'w-5 h-5 text-blue-500 mr-3 mt-1')
            <div>
                <p class="font-bold text-blue-800">{{ __('forms.important') }}</p>
                <p class="text-sm text-blue-600">{{ __('healthcare-services.available_time_info') }}</p>
            </div>
        </div>

        <div
            x-cloak
            x-show="working"
            class="grid overflow-hidden rounded-lg border border-gray-200 md:grid-cols-2 dark:border-gray-700"
        >
            @foreach ($weekdays as $key => $day)
                <div class="p-6 min-h-55 {{ $loop->iteration % 2 === 0 ? '' : 'border-r border-gray-200 dark:border-gray-700' }} {{ $loop->last ? '' : 'border-b border-gray-200 dark:border-gray-700' }} ">
                    <div :key="'{{ $key }}'">
                        <div class="mb-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $day }}</h3>
                        </div>

                        <div class="mb-8 flex items-center gap-8">
                            {{-- Working or not --}}
                            <label class="inline-flex cursor-pointer items-center">
                                <input
                                    type="checkbox"
                                    class="peer sr-only"
                                    x-model="localAvailableTime[{{ $loop->index }}].working"
                                    x-bind:disabled="isDisabled"
                                    @change="if (! localAvailableTime[{{ $loop->index }}].working) {
                                           localAvailableTime[{{ $loop->index }}].allDay = false;
                                           localAvailableTime[{{ $loop->index }}].availableStartTime = null;
                                           localAvailableTime[{{ $loop->index }}].availableEndTime = null;
                                       }"
                                />
                                <div class="peer relative h-6 w-11 rounded-full bg-gray-200 peer-checked:bg-blue-600 peer-focus:ring-4 peer-focus:ring-blue-300 peer-focus:outline-none after:absolute after:start-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full dark:bg-gray-700 dark:peer-checked:bg-blue-600 dark:peer-focus:ring-blue-800"></div>
                                <span
                                    class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                                    x-text="localAvailableTime[{{ $loop->index }}].working ? '{{ __('forms.works') }}' : '{{ __('forms.does_not_work') }}'"
                                ></span>
                            </label>

                            {{-- All day --}}
                            <label
                                class="inline-flex cursor-pointer items-center"
                                x-bind:class="! localAvailableTime[{{ $loop->index }}].working && 'opacity-40 pointer-events-none'"
                            >
                                <input
                                    type="checkbox"
                                    x-model="localAvailableTime[{{ $loop->index }}].allDay"
                                    x-bind:disabled="! localAvailableTime[{{ $loop->index }}].working || isDisabled"
                                    @change="if (localAvailableTime[{{ $loop->index }}].allDay) {
                                           localAvailableTime[{{ $loop->index }}].availableStartTime = null;
                                           localAvailableTime[{{ $loop->index }}].availableEndTime = null;
                                       }"
                                    class="h-5 w-5 rounded-sm border-gray-300 bg-gray-100 text-blue-600 focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:ring-offset-gray-800 dark:focus:ring-blue-600"
                                />
                                <span class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                                    {{ __('healthcare-services.all_day') }}
                                </span>
                            </label>
                        </div>

                        {{-- Show time if working and not all day --}}
                        <div
                            class="flex gap-4"
                            x-show="localAvailableTime[{{ $loop->index }}].working && ! localAvailableTime[{{ $loop->index }}].allDay"
                        >
                            {{-- Start --}}
                            <div class="form-group w-full">
                                <label
                                    for="availableStartTime-{{ $loop->index }}"
                                    class="label !text-xs !text-gray-500 dark:!text-gray-400"
                                >
                                    <span>{{ __('forms.opening') }}</span>
                                </label>
                                <div class="relative w-full">
                                    <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center">
                                        <svg
                                            class="h-5 w-5 text-gray-500 dark:text-gray-400"
                                            aria-hidden="true"
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke="currentColor"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                                            />
                                        </svg>
                                    </div>
                                    <input
                                        type="text"
                                        class="input timepicker-uk border-t-0 border-r-0 border-b border-l-0 border-gray-300 px-0 ps-8 text-gray-900 focus:ring-0 dark:text-white"
                                        placeholder="00:00"
                                        x-ref="start"
                                        id="availableStartTime-{{ $loop->index }}"
                                        x-model="localAvailableTime[{{ $loop->index }}].availableStartTime"
                                        x-bind:disabled="isDisabled"
                                    />
                                </div>
                            </div>

                            {{-- End --}}
                            <div class="form-group w-full">
                                <label
                                    for="availableEndTime-{{ $loop->index }}"
                                    class="label !text-xs !text-gray-500 dark:!text-gray-400"
                                >
                                    <span>{{ __('forms.closing') }}</span>
                                </label>
                                <div class="relative w-full">
                                    <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center">
                                        <svg
                                            class="h-5 w-5 text-gray-500 dark:text-gray-400"
                                            aria-hidden="true"
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke="currentColor"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                                            />
                                        </svg>
                                    </div>
                                    <input
                                        type="text"
                                        class="input timepicker-uk border-t-0 border-r-0 border-b border-l-0 border-gray-300 px-0 ps-8 text-gray-900 focus:ring-0 dark:text-white"
                                        placeholder="00:00"
                                        x-ref="end"
                                        id="availableEndTime-{{ $loop->index }}"
                                        x-model="localAvailableTime[{{ $loop->index }}].availableEndTime"
                                        x-bind:disabled="isDisabled"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div x-show="working" x-cloak class="mt-4 space-y-4">
            <template x-for="(period, idx) in notAvailable" :key="period.frontendId">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700" :id="period.frontendId">
                    <div class="mb-2 flex items-center justify-between">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('healthcare-services.non_working_hours') }}
                            <span x-text="idx + 1"></span>
                        </h4>
                        <button
                            type="button"
                            class="cursor-pointer text-sm font-medium text-red-500 hover:text-red-700"
                            @click="notAvailable.splice(idx, 1)"
                            x-bind:disabled="isDisabled"
                        >
                            @icon('delete', 'w-5 h-5 text-red-600')
                        </button>
                    </div>

                    <div class="form-row-3 mt-5">
                        <div class="form-group datepicker-wrapper relative w-full">
                            <input
                                x-model="period.during.startDate"
                                type="text"
                                name="start"
                                :id="'startDate-' + idx"
                                class="peer input datepicker-input appearance-none pl-10 dark:text-white"
                                placeholder=" "
                                required
                                datepicker-autohide
                                datepicker-button="false"
                                x-bind:disabled="isDisabled"
                            />
                            <label :for="'startDate-' + idx" class="wrapped-label">
                                {{ __('healthcare-services.start_non_working_time') }}
                            </label>
                        </div>

                        <div class="form-group w-full">
                            <label :for="'startTime-' + idx" class="label !text-xs !text-gray-500 dark:!text-gray-400">
                                <span>{{ __('healthcare-services.choose_time') }}</span>
                            </label>
                            <div class="relative w-full">
                                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center">
                                    <svg
                                        class="h-5 w-5 text-gray-500 dark:text-gray-400"
                                        aria-hidden="true"
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke="currentColor"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                                        />
                                    </svg>
                                </div>
                                <input
                                    type="text"
                                    class="input timepicker-uk border-t-0 border-r-0 border-b border-l-0 border-gray-300 px-0 ps-8 text-gray-900 focus:ring-0 dark:border-gray-700 dark:text-white"
                                    placeholder="00:00"
                                    :id="'startTime-' + idx"
                                    x-model="period.during.startTime"
                                    x-bind:disabled="isDisabled"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group datepicker-wrapper relative w-full">
                            <input
                                x-model="period.during.endDate"
                                type="text"
                                name="end"
                                :id="'endDate-' + idx"
                                class="peer input datepicker-input appearance-none pl-10 dark:text-white"
                                placeholder=" "
                                required
                                datepicker-autohide
                                datepicker-button="false"
                                x-bind:disabled="isDisabled"
                            />
                            <label :for="'endDate-' + idx" class="wrapped-label">
                                {{ __('healthcare-services.end_non_working_time') }}
                            </label>
                        </div>

                        <div class="form-group w-full">
                            <label :for="'endTime-' + idx" class="label !text-xs !text-gray-500 dark:!text-gray-400">
                                <span>{{ __('healthcare-services.choose_time') }}</span>
                            </label>
                            <div class="relative w-full">
                                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center">
                                    <svg
                                        class="h-5 w-5 text-gray-500 dark:text-gray-400"
                                        aria-hidden="true"
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke="currentColor"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                                        />
                                    </svg>
                                </div>
                                <input
                                    type="text"
                                    class="input timepicker-uk border-t-0 border-r-0 border-b border-l-0 border-gray-300 px-0 ps-8 text-gray-900 focus:ring-0 dark:border-gray-700 dark:text-white"
                                    placeholder="00:00"
                                    :id="'endTime-' + idx"
                                    x-model="period.during.endTime"
                                    x-bind:disabled="isDisabled"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <input
                            x-model="period.description"
                            type="text"
                            name="description"
                            :id="'description-' + idx"
                            class="peer input dark:text-white"
                            placeholder=" "
                            x-bind:disabled="isDisabled"
                            required
                        />
                        <label :for="'description-' + idx" class="label">
                            {{ __('healthcare-services.comment_non_working_hours') }}
                        </label>
                    </div>
                </div>
            </template>

            <div class="form-group mt-2 mb-4">
                <button @click.prevent="addNotAvailable" class="item-add" x-bind:disabled="isDisabled">
                    {{ __('healthcare-services.add_non_working_hours') }}
                </button>
            </div>
        </div>
    </div>
</fieldset>
