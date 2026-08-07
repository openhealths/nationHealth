<div class="px-4 pt-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold text-gray-900 sm:text-2xl dark:text-white">Медичні Вироби (Device Requests)</h1>
        <button type="button" data-modal-target="create-device-modal" data-modal-toggle="create-device-modal" class="text-white bg-primary-700 hover:bg-primary-800 focus:ring-4 focus:ring-primary-300 font-medium rounded-lg text-sm px-5 py-2.5 dark:bg-primary-600 dark:hover:bg-primary-700 focus:outline-none dark:focus:ring-primary-800">
            Виписати медичний виріб
        </button>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg shadow-sm dark:border-gray-700 sm:p-6 dark:bg-gray-800">
        <div class="w-full mb-4">
            <p class="text-sm font-normal text-gray-500 dark:text-gray-400">
                Тут буде відображатися список призначених медичних виробів (напр. тест-смужки) для пацієнтів.
            </p>
        </div>
        
        <!-- Placeholder for table/list -->
        <div class="p-4 text-sm text-gray-700 bg-gray-100 rounded-lg dark:bg-gray-700 dark:text-gray-300">
            Список порожній.
        </div>
    </div>

    <!-- Create Modal (Includes DeviceRequestForm) -->
    <div id="create-device-modal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-modal md:h-full">
        <div class="relative p-4 w-full max-w-4xl h-full md:h-auto">
            <div class="relative bg-white rounded-lg shadow dark:bg-gray-800">
                <div class="flex justify-between items-start p-4 rounded-t border-b dark:border-gray-600">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                        Виписати Медичний Виріб
                    </h3>
                    <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="create-device-modal">
                        @icon('close')
                    </button>
                </div>
                <div class="p-6 space-y-6">
                    @livewire('device-request.device-request-form', ['legalEntity' => $legalEntity])
                </div>
            </div>
        </div>
    </div>
</div>
