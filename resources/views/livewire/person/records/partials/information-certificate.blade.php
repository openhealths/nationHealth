@use('Picqer\Barcode\Types\TypeCode128A')
@use('Picqer\Barcode\Renderers\DynamicHtmlRenderer')

<div
    x-show="showCertificate"
    style="display:none"
    @keydown.escape.window.prevent.stop="showCertificate = false"
    role="dialog"
    aria-modal="true"
    class="modal"
>
    <div x-transition.opacity class="fixed inset-0 bg-black/40"></div>
    <div x-transition @click="showCertificate = false" class="modal-wrapper">

        <div
            @click.stop x-trap.noscroll.inert="showCertificate"
            class="modal-content w-full max-w-2xl mx-auto bg-white dark:bg-gray-900 rounded-xl shadow-2xl"
        >
            <div id="certificate-print-area" class="p-8">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">
                    {{ __('preperson.info_certificate') }}
                </h2>

                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ __('preperson.label_single') }}</p>

                @php
                    $barcode = (new TypeCode128A())->getBarcode(strtoupper($preperson->uuid));
                    $barcodeHtml = (new DynamicHtmlRenderer())->render($barcode);
                @endphp

                <div class="flex flex-col items-center gap-2 mb-6">
                    <div class="w-full bg-white p-2 rounded">
                        <div
                            style="width:100%;min-width:9cm;height:1.2cm;print-color-adjust:exact;-webkit-print-color-adjust:exact"
                        >
                            {!! $barcodeHtml !!}
                        </div>
                    </div>
                    <span class="text-xs font-mono tracking-widest text-gray-900 dark:text-white">
                        {{ $preperson->uuid }}
                    </span>
                </div>

                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-3">{{ __('patients.main_info') }}</h3>
                <table
                    class="w-full text-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden mb-6">
                    <tbody>
                    @php
                        $rows = [
                            [__('preperson.external_id') . ':', $preperson->externalId],
                            [__('forms.first_name') . ':', $preperson->firstName ?: '-'],
                            [__('forms.last_name') . ':', $preperson->lastName ?: '-'],
                            [__('forms.second_name') . ':', $preperson->secondName ?: '-'],
                            [__('forms.gender') . ':', $preperson->gender->label()],
                            [__('forms.birth_date') . ':', $preperson->birthDate ?: '-'],
                            [__('preperson.note') . ':', $preperson->note ?: '-'],
                            [__('preperson.contact_first_name') . ':', $preperson->emergencyContact['first_name'] ?? '-'],
                            [__('preperson.contact_last_name') . ':', $preperson->emergencyContact['last_name'] ?? '-'],
                            [__('preperson.contact_second_name') . ':', $preperson->emergencyContact['second_name'] ?? '-'],
                        ];
                    @endphp
                    @foreach($rows as [$label, $value])
                        <tr class="border-b border-gray-200 dark:border-gray-700 last:border-0">
                            <td class="px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 w-1/2 bg-gray-50 dark:bg-gray-800">
                                {{ $label }}
                            </td>
                            <td class="px-4 py-2.5 text-gray-900 dark:text-white">
                                {{ $value }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-3">
                    {{ __('preperson.emergency_contact_phones') }}
                </h3>
                <table class="w-full text-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                    <tbody>
                    @php
                        $emergencyPhones = $emergencyContact['phones'] ?? [];
                        $phoneTypes = dictionary()->basics()->byName('PHONE_TYPE')->asCodeDescription()->toArray();
                    @endphp
                    @forelse($emergencyPhones as $phone)
                        <tr class="border-b border-gray-200 dark:border-gray-700 last:border-0">
                            <td class="px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 w-1/2 bg-gray-50 dark:bg-gray-800">
                                {{ ($phoneTypes[$phone['type'] ?? ''] ?? '-') . ':' }}
                            </td>
                            <td class="px-4 py-2.5 text-gray-900 dark:text-white">
                                {{ $phone['number'] ?? '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="2"
                                class="px-4 py-2.5 text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800"
                            >
                                {{ __('forms.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3 px-8 pb-6 border-t border-gray-100 dark:border-gray-800 pt-4"
                 x-data="{
                     printCertificate() {
                         const area = document.getElementById('certificate-print-area');
                         const win = window.open('', '_blank', 'width=800,height=900');
                         const doc = win.document;
                         doc.title = '{{ __('preperson.info_certificate') }}';

                         const style = doc.createElement('style');
                         style.textContent = `
                             * { box-sizing: border-box; margin: 0; padding: 0; }
                             body { font-family: Arial, sans-serif; font-size: 13px; color: #111; background: #fff; padding: 32px; }
                             h2 { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
                             h3 { font-size: 14px; font-weight: 700; margin: 20px 0 8px; }
                             p  { font-size: 12px; color: #555; margin-bottom: 4px; }
                             svg { display: block; margin: 0 auto 20px; }
                             table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                             tr { border-bottom: 1px solid #e5e7eb; }
                             tr:last-child { border-bottom: none; }
                             td { padding: 8px 14px; font-size: 12px; vertical-align: top; }
                             td:first-child { background: #f9fafb; font-weight: 600; text-transform: uppercase; color: #6b7280; font-size: 10px; width: 50%; }
                             @media print {
                                 body { padding: 20px; }
                                 @page { margin: 15mm; }
                             }
                         `;
                         doc.head.appendChild(style);
                         doc.body.innerHTML = area.innerHTML;

                         win.focus();
                         setTimeout(() => win.print(), 400);
                     }
                 }"
            >
                <button type="button" class="button-minor" @click="showCertificate = false">
                    {{ __('forms.close') }}
                </button>
                <button
                    type="button"
                    class="button-primary-outline flex items-center gap-2"
                    @click="printCertificate()"
                >
                    @icon('printer', 'w-4 h-4')
                    <span>{{ __('preperson.print') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>
