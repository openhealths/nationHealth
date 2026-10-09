<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :title="__('referrals.creation_title') . ' - ' . $patientFullName"
    :hideNavigation="true"
    :breadcrumbs="[
        ['label' => __('general.home') , 'url' => route('dashboard', [legalEntity()])],
        ['label' => __('patients.patients') , 'url' => route('persons.index', [legalEntity()])],
        ['label' => $patientFullName , 'url' => $personId ? route('persons.summary', [legalEntity(), $personId]) : '#'],
        ['label' => __('referrals.new_referral')]
    ]"
>
    <x-slot name="headerActions"></x-slot>

    <div class="shift-content mt-6 pl-4" x-data="{ showSignatureDrawer: false }">
        <div class="w-full max-w-screen-xl">

            <div class="index-table-wrapper mb-6">
                <table class="index-table">
                    <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th">{{ __('referrals.service_code_name') }}</th>
                            <th class="index-table-th">{{ __('referrals.quantity') }}</th>
                            <th class="index-table-th">{{ __('referrals.category') }}</th>
                            <th class="index-table-th">{{ __('referrals.status') }}</th>
                            <th class="index-table-th w-16 text-right">{{ __('forms.action')  }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($referrals as $referral)
                            <tr class="index-table-tr group">
                                <td class="index-table-td-primary">{{ $referral['code_name'] }}</td>
                                <td class="index-table-td">{{ $referral['quantity'] }}</td>
                                <td class="index-table-td">{{ $referral['category'] }}</td>
                                <td class="index-table-td">
                                    @php
                                        $statusClass = 'badge-gray';
                                        $statusText = $referral['status'] ?? '';
                                        if (in_array($statusText, ['Нове', 'Готове до підписання', 'new', 'active'])) {
                                            $statusClass = 'badge-green';
                                        } elseif (in_array($statusText, ['В роботі', 'in_progress', 'draft', 'in_queue'])) {
                                            $statusClass = 'badge-yellow';
                                        } elseif (in_array($statusText, ['Виконане', 'completed'])) {
                                            $statusClass = 'badge-blue';
                                        } elseif (in_array($statusText, ['Відкликане', 'entered_in_error', 'Помилково введене'])) {
                                            $statusClass = 'badge-red';
                                        }
                                    @endphp
                                    <span class="{{ $statusClass }} !me-0 inline-block whitespace-nowrap text-center leading-tight">
                                        {{ $statusText ?: __('referrals.ready_to_sign') }}
                                    </span>
                                </td>
                                <td class="index-table-td text-right">
                                    <div x-data="{ open: false }"
                                         x-id="['dropdown-button']"
                                         class="relative inline-block text-left">
                                        <button
                                            @click="open = !open"
                                            :aria-controls="$id('dropdown-button')"
                                            type="button"
                                            class="hover:text-primary cursor-pointer"
                                        >
                                            <svg class="svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300"
                                                 aria-hidden="true"
                                                 xmlns="http://www.w3.org/2000/svg"
                                                 width="18" height="18"
                                                 fill="none" viewBox="0 0 24 24">
                                                <path stroke="currentColor"
                                                      stroke-linecap="round"
                                                      stroke-linejoin="round"
                                                      stroke-width="2"
                                                      d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                            </svg>
                                        </button>

                                        <div x-show="open"
                                             x-cloak
                                             x-transition.origin.top.left
                                             @click.outside="open = false"
                                             :id="$id('dropdown-button')"
                                             class="absolute right-0 mt-2 w-40 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50"
                                             style="display: none;">
                                            <a href="#"
                                               class="flex items-center gap-2 w-full first-of-type:rounded-t-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                @icon('edit', 'w-4 h-4 text-gray-600 dark:text-gray-300')
                                                {{ __('forms.edit')  }}
                                            </a>
                                            <a href="#"
                                               class="flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-red-600 hover:bg-gray-50 dark:hover:bg-gray-600">
                                                @icon('delete', 'w-4 h-4 text-red-500')
                                                {{ __('forms.delete')  }}
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mb-8">
                <a href="{{ route(!is_null($prepersonId) ? 'prepersons.encounter.referral.create' : 'encounter.referral.create', ['legalEntity' => legalEntity(), (!is_null($prepersonId) ? 'preperson' : 'person') => $prepersonId ?? $personId, 'encounterId' => $encounter->id]) }}"
                   class="flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                    + {{ __('referrals.add_referral') }}
                </a>
            </div>

            <div class="mb-10 w-96">
                <div class="form-group group">
                    <select class="input-select peer w-full">
                        <option>{{ __('referrals.via_sms') }}</option>
                    </select>
                    <label class="label">{{ __('referrals.inform_patient') }}*</label>
                </div>
            </div>

            <div class="mb-10 mt-8 flex items-center gap-4">
                <button type="button" class="button-primary-outline-red px-6 py-2.5">
                    {{ __('forms.delete')  }}
                </button>
                <button type="button" class="button-primary-outline flex items-center gap-2 px-6 py-2.5">
                    @icon('archive', 'w-4 h-4')
                    {{ __('forms.save')  }}
                </button>
                <button type="button" @click="showSignatureDrawer = true" class="button-primary px-8 py-2.5">
                    {{ __('referrals.sign_referral') }}
                </button>
            </div>
        </div>

        <div x-show="showSignatureDrawer"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             x-cloak
             @click="showSignatureDrawer = false"
             class="fixed inset-0 bg-gray-900/70"
             style="z-index: 46;">
        </div>

        <div x-show="showSignatureDrawer"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             x-cloak
             @click.stop
             class="fixed top-0 right-0 h-screen pt-20 bg-white dark:bg-gray-800 shadow-2xl"
             style="z-index: 47; width: 50%;"
             id="signature-drawer"
             tabindex="-1"
             x-data="{ fileUploaded: false, fileName: '' }">

            <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-6 py-4">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                    {{ __('forms.sign_with_KEP')  }}
                </h2>
            </div>

            <div class="overflow-y-auto p-6 bg-white dark:bg-gray-800" style="height: calc(100% - 70px);">
                <div class="flex flex-col gap-6">
                    <div>
                        <label for="referralKnedp" class="default-label">{{ __('forms.knedp')  }} *</label>
                        <select class="input-modal w-full" name="referralKnedp" id="referralKnedp">
                            <option value="" selected>{{ __('forms.select')  }}</option>
                            @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                                <option value="{{ $certificateType['id'] }}">
                                    {{ $certificateType['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="default-label">{{ __('forms.key_container_upload')  }} *</label>
                        <label for="referralKeyFile"
                               class="flex flex-col items-center justify-center w-full h-48 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:hover:border-gray-500">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                <svg class="w-8 h-8 mb-4 text-gray-500 dark:text-gray-400"
                                     aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                                     fill="none" viewBox="0 0 20 16">
                                    <path stroke="currentColor" stroke-linecap="round"
                                          stroke-linejoin="round" stroke-width="2"
                                          d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"/>
                                </svg>
                                <p class="mb-2 px-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                                    <span class="font-semibold text-blue-600 dark:text-blue-400">{{ __('forms.drag_key_file')  }}</span>
                                    {{ __('forms.or_upload_from_device')  }}
                                </p>
                                <p class="px-2 text-xs text-gray-500 dark:text-gray-400 text-center">
                                    {{ __('forms.key_file_extension_hint')  }}
                                </p>
                            </div>
                            <input id="referralKeyFile"
                                   type="file"
                                   class="hidden"
                                   accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                   @change="fileUploaded = true; fileName = $event.target.files[0].name"/>
                        </label>
                        <template x-if="fileUploaded">
                            <div x-transition class="text-sm text-green-700 mt-2" x-text="fileName"></div>
                        </template>
                    </div>

                    <div>
                        <label for="referralPassword" class="default-label">{{ __('forms.password')  }} *</label>
                        <input type="password"
                               class="default-input w-full"
                               id="referralPassword"
                               name="referralPassword"
                               autocomplete="current-password"/>
                    </div>
                </div>

                <div class="flex gap-3 mt-8">
                    <button type="button"
                            @click="showSignatureDrawer = false"
                            class="button-minor">
                        {{ __('forms.cancel')  }}
                    </button>
                    <button type="button" class="button-primary">
                        {{ __('forms.sign')  }}
                    </button>
                </div>
            </div>
        </div>

    </div>
</x-layouts.patient>
