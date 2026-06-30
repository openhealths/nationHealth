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

        <div @click.stop x-trap.noscroll.inert="showCertificate"
             class="modal-content w-full max-w-2xl mx-auto bg-white dark:bg-gray-900 rounded-xl shadow-2xl"
        >
            <div id="certificate-print-area" class="p-8">

                <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">
                    Інформаційна довідка
                </h2>

                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Неідентифікований пацієнт</p>
                <p class="text-sm font-mono text-gray-900 dark:text-white mb-5 uppercase tracking-wide">
                    {{ $uuid }}
                </p>

                <div class="flex items-center justify-center mb-6">
                    @php
                        $patterns = [
                            '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
                            '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
                            '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
                            '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
                            '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
                            '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
                            '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
                            '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
                            '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
                            '214121', '412121', '111143', '111341', '131141', '114113',
                            '114311', '411113', '411311', '113141', '114131', '311141', '411131',
                            '211412', '211214', '211232',
                            '2331112'
                        ];

                        $encodedValues = [103];
                        $checksum = 103;

                        $len = strlen($uuid);
                        for ($i = 0; $i < $len; $i++) {
                            $char = $uuid[$i];
                            $val = ord($char) - 32;
                            if ($val < 0 || $val > 95) {
                                $val = 0;
                            }
                            $encodedValues[] = $val;
                            $checksum += ($i + 1) * $val;
                        }

                        $checkDigit = $checksum % 103;
                        $encodedValues[] = $checkDigit;
                        $encodedValues[] = 106;

                        $svgContent = '';
                        $x = 0;
                        foreach ($encodedValues as $val) {
                            $pattern = $patterns[$val];
                            $patLen = strlen($pattern);
                            for ($p = 0; $p < $patLen; $p++) {
                                $width = (int)$pattern[$p];
                                $isBar = ($p % 2 === 0);
                                if ($isBar) {
                                    $svgContent .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="80" fill="#000"/>';
                                }
                                $x += $width;
                            }
                        }
                    @endphp
                    <svg viewBox="0 0 {{ $x }} 80" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" class="w-full" style="width: 100%; height: 2cm; min-width: 9cm; min-height: 1.2cm; display: block; margin: 0 auto;">
                        {!! $svgContent !!}
                    </svg>
                </div>

                <h3 class="text-base font-bold text-gray-900 dark:text-white mb-3">Основна інформація</h3>
                <table class="w-full text-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden mb-6">
                    <tbody>
                        @php
                            $rows = [
                                ["ІДЕНТИФІКАТОР ПАЦІЄНТА В ЗАКЛАДІ ОХОРОНИ ЗДОРОВ'Я:", $taxId ?? '-'],
                                ["ІМ'Я ПАЦІЄНТА:", $firstName ?? '-'],
                                ["ПРІЗВИЩЕ ПАЦІЄНТА:", $lastName ?? '-'],
                                ["ПО-БАТЬКОВІ ПАЦІЄНТА:", $secondName ?? '-'],
                                ["СТАТЬ:", $gender === 'FEMALE' ? 'Жіноча' : 'Чоловіча'],
                                ["ДАТА НАРОДЖЕННЯ:", $birthDate ?? '-'],
                                ["ІМ'Я КОНТАКТНОЇ ОСОБИ:", $emergencyContact['firstName'] ?? '-'],
                                ["ПРІЗВИЩЕ КОНТАКТНОЇ ОСОБИ:", $emergencyContact['lastName'] ?? '-'],
                                ["ПО-БАТЬКОВІ КОНТАКТНОЇ ОСОБИ:", $emergencyContact['secondName'] ?? '-'],
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
                    Контактні телефони для екстреного зв'язку
                </h3>
                <table class="w-full text-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                    <tbody>
                        @php
                            $emergencyPhones = $emergencyContact['phones'] ?? [];
                            $phoneTypeLabels = [
                                'MOBILE' => 'МОБІЛЬНИЙ НОМЕР ТЕЛЕФОНА:',
                                'LAND_LINE' => 'ДОМАШНІЙ НОМЕР ТЕЛЕФОНА:',
                            ];
                        @endphp
                        @forelse($emergencyPhones as $phone)
                            <tr class="border-b border-gray-200 dark:border-gray-700 last:border-0">
                                <td class="px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 w-1/2 bg-gray-50 dark:bg-gray-800">
                                    {{ $phoneTypeLabels[$phone['type'] ?? 'MOBILE'] ?? strtoupper($phone['type'] ?? 'ТЕЛЕФОН') . ':' }}
                                </td>
                                <td class="px-4 py-2.5 text-gray-900 dark:text-white">
                                    {{ $phone['number'] ?? '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-4 py-2.5 text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800">
                                    Дані відсутні
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
                         const win  = window.open('', '_blank', 'width=800,height=900');
                         win.document.write(`
                             <!DOCTYPE html>
                             <html lang='uk'>
                             <head>
                                 <meta charset='UTF-8'>
                                 <title>Інформаційна довідка</title>
                                 <style>
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
                                 </style>
                             </head>
                             <body>` + area.innerHTML + `</body></html>`
                         );
                         win.document.close();
                         win.focus();
                         setTimeout(() => { win.print(); }, 400);
                     }
                 }"
            >
                <button type="button"
                        class="button-minor"
                        @click="showCertificate = false"
                >
                    Закрити
                </button>
                <button type="button"
                        class="button-primary-outline flex items-center gap-2"
                        @click="printCertificate()"
                >
                    @icon('printer', 'w-4 h-4')
                    <span>Надрукувати</span>
                </button>
            </div>
        </div>
    </div>
</div>
