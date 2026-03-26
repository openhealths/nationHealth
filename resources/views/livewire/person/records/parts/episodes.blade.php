                                <div class="record-inner-card">
                                    <div class="record-inner-header">
                                        <div class="p-4 flex items-center justify-center shrink-0 w-14 border-b md:border-b-0 md:border-r border-gray-200 dark:border-gray-700">
                                            <input type="checkbox" class="default-checkbox w-5 h-5">
                                        </div>

                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                                            <div class="record-inner-value text-[16px]">030.2 | Чотириплідна вагітність</div>
                                        </div>

                                        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
                                            <div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                    {{ __('patients.active_status') }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="record-inner-column-bordered w-full md:w-16 shrink-0 md:!items-center relative" x-data="{ openMenu: false }">
                                            <button @click="openMenu = !openMenu"
                                                    @click.away="openMenu = false"
                                                    class="text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 transition-colors"
                                            >
                                                @icon('edit-user-outline', 'w-5 h-5')
                                            </button>

                                            <!-- Dropdown Menu -->
                                            <div x-show="openMenu"
                                                 x-transition.opacity.duration.200ms
                                                 class="absolute right-[50%] md:right-0 top-1/2 md:top-[80%] w-56 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-lg rounded-xl z-20 py-2"
                                                 style="display: none;"
                                            >
                                                <button class="w-full text-left px-4 py-2.5 text-[14px] text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors">
                                                    <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                    </svg>
                                                    {{ __('patients.view_details') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="record-inner-body">
                                        <div class="flex-1 p-4 md:pl-[72px] flex flex-col justify-center">
                                            <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.date_opened') }}</div>
                                                    <div class="record-inner-value">02.04.2025</div>
                                                </div>
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.date_closed') }}</div>
                                                    <div class="record-inner-value">02.04.2025</div>
                                                </div>
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.date_updated') }}</div>
                                                    <div class="record-inner-value">02.02.2025</div>
                                                </div>
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                                                    <div class="record-inner-value">Сидоренко І.В.</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="w-full md:w-52 shrink-0 border-t md:border-t-0 md:border-l border-gray-200 dark:border-gray-700 p-4 flex flex-col justify-center gap-3">
                                            <div class="min-w-0">
                                                <div class="record-inner-label">ID ECO3</div>
                                                <div class="text-[13px] font-medium text-gray-800 dark:text-gray-300 truncate">1231-adsadas-aqeqe-casdda</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="record-inner-card">
                                    <div class="record-inner-header">
                                        <div class="p-4 flex items-center justify-center shrink-0 w-14 border-b md:border-b-0 md:border-r border-gray-200 dark:border-gray-700">
                                            <input type="checkbox" class="default-checkbox w-5 h-5">
                                        </div>

                                        <div class="record-inner-column flex-1">
                                            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                                            <div class="record-inner-value text-[16px]">030.2 | Чотириплідна вагітність</div>
                                        </div>

                                        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
                                            <div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                    {{ __('patients.active_status') }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="record-inner-column-bordered w-full md:w-16 shrink-0 md:!items-center relative" x-data="{ openMenu: false }">
                                            <button @click="openMenu = !openMenu"
                                                    @click.away="openMenu = false"
                                                    class="text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 transition-colors"
                                            >
                                                @icon('edit-user-outline', 'w-5 h-5')
                                            </button>

                                            <!-- Dropdown Menu -->
                                            <div x-show="openMenu"
                                                 x-transition.opacity.duration.200ms
                                                 class="absolute right-[50%] md:right-0 top-1/2 md:top-[80%] w-64 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-lg rounded-xl z-20 py-2"
                                                 style="display: none;"
                                            >
                                                <button class="w-full text-left px-4 py-2.5 text-[14px] text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors">
                                                    <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                    </svg>
                                                    {{ __('patients.get_data_access') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="record-inner-body">
                                        <div class="flex-1 p-4 md:pl-[72px] flex flex-col justify-center">
                                            <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.date_opened') }}</div>
                                                    <div class="record-inner-value">02.04.2025</div>
                                                </div>
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.date_closed') }}</div>
                                                    <div class="record-inner-value">02.04.2025</div>
                                                </div>
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.date_updated') }}</div>
                                                    <div class="record-inner-value">02.02.2025</div>
                                                </div>
                                                <div>
                                                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                                                    <div class="record-inner-value">Сидоренко І.В.</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="w-full md:w-52 shrink-0 border-t md:border-t-0 md:border-l border-gray-200 dark:border-gray-700 p-4 flex flex-col justify-center gap-3">
                                            <div class="min-w-0">
                                                <div class="record-inner-label">ID ECO3</div>
                                                <div class="text-[13px] font-medium text-gray-800 dark:text-gray-300 truncate">1231-adsadas-aqeqe-casdda</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
