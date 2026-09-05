<aside class="fixed top-0 right-0 z-40 h-screen w-64 translate-x-full border-l border-gray-200 bg-white pt-20 transition-transform lg:translate-x-0 lg:pt-0 dark:border-gray-700 dark:bg-gray-800">
    <div class="h-full overflow-y-auto bg-white px-3 py-5 dark:bg-gray-800">
        <ul class="mt-5 space-y-2 border-t border-gray-200 pt-5 dark:border-gray-700">
            <li>
                <a href="#patient-data-section" class="encounter-nav-item">
                    @icon('pie-chart', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('forms.main_information') }}</p>
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
                    <p class="default-p">{{ __('encounters.reasons_for_visit') }}</p>
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
                    <p class="default-p">{{ __('observations.label') }}</p>
                </a>
            </li>
            <li>
                <a href="#immunizations-section" class="encounter-nav-item">
                    @icon('shield', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('immunizations.plural') }}</p>
                </a>
            </li>
            <li>
                <a href="#procedures-section" class="encounter-nav-item">
                    @icon('settings', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('procedures.plural') }}</p>
                </a>
            </li>
            <li>
                <a href="#diagnostic-reports-section" class="encounter-nav-item">
                    @icon('activity', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('diagnostic-reports.plural') }}</p>
                </a>
            </li>
            <li>
                <a href="#clinical-impressions-section" class="encounter-nav-item">
                    @icon('check', 'w-6 h-6 dark:text-white')
                    <p class="default-p">{{ __('clinical-impressions.plural') }}</p>
                </a>
            </li>
        </ul>
    </div>
</aside>
