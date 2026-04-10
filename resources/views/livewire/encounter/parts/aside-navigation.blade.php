<aside
    class="fixed top-0 right-0 z-40 w-64 h-screen pt-20 lg:pt-0 transition-transform translate-x-full bg-white border-l border-gray-200 lg:translate-x-0 dark:bg-gray-800 dark:border-gray-700"
>
    <div class="overflow-y-auto py-5 px-3 h-full bg-white dark:bg-gray-800">
        <ul class="pt-5 mt-5 space-y-2 border-t border-gray-200 dark:border-gray-700">
            <li>
                <a href="#patient-data-section" class="encounter-nav-item">
                    @icon('pie-chart', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.main_data') }}</p>
                </a>
            </li>
            <li>
                <a href="#diagnoses-section" class="encounter-nav-item">
                    @icon('file', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.diagnoses') }}</p>
                </a>
            </li>
            <li>
                <a href="#reasons-section" class="encounter-nav-item">
                    @icon('person', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.reasons_for_visit') }}</p>
                </a>
            </li>
            <li>
                <a href="#actions-section" class="encounter-nav-item">
                    @icon('check-box', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('forms.actions') }}</p>
                </a>
            </li>
            <li>
                <a href="#observations-section" class="encounter-nav-item">
                    @icon('heart', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.observation') }}</p>
                </a>
            </li>
            <li>
                <a href="#immunizations-section" class="encounter-nav-item">
                    @icon('shield', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.immunizations') }}</p>
                </a>
            </li>
            <li>
                <a href="#procedures-section" class="encounter-nav-item">
                    @icon('settings', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.procedures') }}</p>
                </a>
            </li>
            <li>
                <a href="#diagnostic-reports-section" class="encounter-nav-item">
                    @icon('activity', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.diagnostic_reports') }}</p>
                </a>
            </li>
            <li>
                <a href="#clinical-impressions-section" class="encounter-nav-item">
                    @icon('check', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('patients.clinical_impressions') }}</p>
                </a>
            </li>
        </ul>
    </div>
</aside>
