<div
    x-show="stage === 'review'"
    x-cloak
    data-referral-prototype-review
    class="mt-4 w-full max-w-screen-xl"
>
    <p
        x-show="items.some((item) => item.service?.is_demo)"
        class="mb-3 text-sm text-gray-500 dark:text-gray-400"
    >
        {{ __('encounters.referral_layout.preview_referrals') }}
    </p>
    <div class="index-table-wrapper">
        <table
            class="index-table min-w-0! border-b-4 border-gray-200 dark:border-gray-600"
        >
            <colgroup>
                <col style="width: 40%" />
                <col style="width: 13%" />
                <col style="width: 20%" />
                <col style="width: 20%" />
                <col style="width: 7%" />
            </colgroup>
            <thead class="index-table-thead">
                <tr>
                    <th class="index-table-th">
                        {{ __('referrals.service_code_name') }}
                    </th>
                    <th class="index-table-th">
                        {{ __('referrals.quantity') }}
                    </th>
                    <th class="index-table-th">
                        {{ __('referrals.category') }}
                    </th>
                    <th class="index-table-th">{{ __('referrals.status') }}</th>
                    <th class="index-table-th text-center">
                        {{ __('forms.action') }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <template x-for="item in items" :key="item.id">
                    <tr class="index-table-tr" :data-preview-referral="item.id">
                        <td
                            class="index-table-td-primary"
                            x-text="
                                [item.service.code, item.service.name]
                                    .filter(Boolean)
                                    .join(' - ')
                            "
                        ></td>
                        <td
                            class="index-table-td whitespace-nowrap"
                            x-text="item.form.quantity + ' ' + (item.quantityUnitLabel || item.form.quantity_unit || @js(__('referrals.pcs')))"
                        ></td>
                        <td
                            class="index-table-td"
                            x-text="item.categoryLabel || item.form.category"
                        ></td>
                        <td class="index-table-td">
                            <span
                                class="badge-green"
                                >{{ __('referrals.ready_to_sign') }}</span
                            >
                        </td>
                        <td class="index-table-td text-center">
                            <x-patient.record-actions>
                                <button
                                    type="button"
                                    @click="
                                        editItem(item.id);
                                        close();
                                    "
                                    class="dropdown-item"
                                >
                                    @icon ('edit', 'w-5 h-5')
                                    {{ __('forms.edit') }}
                                </button>
                                <button
                                    type="button"
                                    @click="
                                        removeItem(item.id);
                                        close();
                                    "
                                    class="dropdown-item dropdown-delete text-red-600! dark:text-red-400!"
                                >
                                    @icon ('trash', 'w-5 h-5')
                                    {{ __('forms.delete') }}
                                </button>
                            </x-patient.record-actions>
                        </td>
                    </tr>
                </template>
                <tr x-show="!items.length">
                    <td colspan="5" class="index-table-td">
                        {{ __('forms.nothing_found') }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <button
        type="button"
        @click="newItem()"
        class="item-add flex! mt-4 w-fit text-left"
    >
        {{ __('referrals.add_referral') }}
    </button>
    <div class="form-group mt-10 max-w-md">
        <label
            for="referralPreviewInformMethod"
            class="mb-1 block text-xs text-gray-500 dark:text-gray-400"
        >
            {{ __('referrals.inform_patient') }}
        </label>
        <select
            id="referralPreviewInformMethod"
            x-model="informMethod"
            @change="prototypeSaved = false"
            class="input-select peer w-full"
        >
            <option value="sms">{{ __('referrals.via_sms') }}</option>
            <option value="none">
                {{ __('encounters.referral_layout.without_notification') }}
            </option>
        </select>
    </div>
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <button
            type="button"
            @click="discard()"
            class="button-primary-outline-red"
        >
            {{ __('forms.delete') }}
        </button>
        <button
            type="button"
            @click="persist(true)"
            class="button-primary-outline inline-flex items-center gap-2"
        >
            @icon ('file-text', 'h-4 w-4')
            {{ __('forms.save') }}
        </button>
        <button type="button" @click="openSignature()" class="button-primary">
            {{ __('referrals.sign_referral') }}
        </button>
        <span
            x-show="prototypeSaved"
            x-cloak
            role="status"
            class="text-sm text-gray-500 dark:text-gray-300"
            >{{ __('encounters.referral_layout.preview_saved') }}</span
        >
    </div>
</div>
