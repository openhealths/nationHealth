                                <div class="record-inner-card">
                                    <div class="record-inner-header">
                                        <div class="p-4 flex items-center justify-center shrink-0 w-14 border-b md:border-b-0 md:border-r border-gray-200 dark:border-gray-700">
                                            <input type="checkbox" class="default-checkbox w-5 h-5">
                                        </div>

                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">{{ __('patients.date') }}</div>
                                            <div class="record-inner-value text-[20px] font-semibold">02.04.2025</div>
                                        </div>

                                        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
                                            <div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                    {{ __('patients.active_status') }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="record-inner-column-bordered w-full md:w-16 shrink-0 md:!items-center">
                                            <button class="text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                                                @icon('edit-user-outline', 'w-5 h-5')
                                            </button>
                                        </div>
                                    </div>

                                    <div class="record-inner-body">
                                        <div class="flex-1 p-4 md:pl-[72px] flex flex-col justify-center">
                                            <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                                                <div class="flex-1 min-w-0">
                                                    <div class="record-inner-label">{{ __('patients.class') }}</div>
                                                    <div class="record-inner-value truncate">{{ __('patients.inpatient_care') }}</div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="record-inner-label">{{ __('patients.type') }}</div>
                                                    <div class="record-inner-value truncate">{{ __('patients.health_facility_interaction') }}</div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="record-inner-label">{{ __('patients.doctor_speciality') }}</div>
                                                    <div class="record-inner-value truncate">{{ __('patients.surgery') }}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="w-full md:w-52 shrink-0 border-t md:border-t-0 md:border-l border-gray-200 dark:border-gray-700 p-4 flex flex-col justify-center gap-3">
                                            <div class="min-w-0">
                                                <div class="record-inner-label">ID ECO3</div>
                                                <div class="text-[13px] font-medium text-gray-800 dark:text-gray-300 truncate">1231-adsadas-aqeqe-casdda</div>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="record-inner-label">ID Епізоду</div>
                                                <div class="text-[13px] font-medium text-gray-800 dark:text-gray-300 truncate">1231-adsadas-aqeqe-casdda</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
