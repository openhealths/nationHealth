import { initFlowbite } from 'flowbite';
import 'flowbite';
import './bootstrap';
import './common';
import './index';

import Datepicker from 'flowbite-datepicker/Datepicker';
import uk from '../../node_modules/flowbite-datepicker/js/i18n/locales/uk.js';

// Date input mask DD.MM.YYYY
function attachDateMask(el) {
    if (el._date_mask_initialized) return;
    el._date_mask_initialized = true;
    el.setAttribute('data-date-mask', 'true');

    let lastInputType = '';

    el.addEventListener('beforeinput', (e) => {
        lastInputType = e.inputType || '';

        if (e.inputType === 'deleteContentBackward') {
            const pos = el.selectionStart;
            const endPos = el.selectionEnd;
            if (pos === endPos && pos > 0 && el.value[pos - 1] === '.') {
                e.preventDefault();
                const val = el.value;
                const before = val.substring(0, pos - 2);
                const after = val.substring(pos);
                el.value = before + '.' + after.replace(/^\./, '');
                const newPos = Math.max(pos - 2, 0);
                el.setSelectionRange(newPos, newPos);
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });

    el.addEventListener('input', (e) => {
        if (e._fromDateMask || e._fromChangeDate) return;

        const rawVal = el.value;
        const cursorPos = el.selectionStart;

        const isSeparatorInsert = (
            lastInputType === 'insertText' &&
            e.data && /^[.,\/ \-]$/.test(e.data)
        );

        let day = '', month = '', year = '';
        const hasSeparator = rawVal.includes('.') || rawVal.includes('/') || rawVal.includes('-') || rawVal.includes(' ');

        if (hasSeparator) {
            const parts = rawVal.split(/[.,\/ \-]/);
            day = (parts[0] || '').replace(/\D/g, '');

            if (parts.length > 2) {
                month = (parts[1] || '').replace(/\D/g, '');
                year = (parts[2] || '').replace(/\D/g, '');
            } else if (parts.length === 2) {
                const p1 = (parts[1] || '').replace(/\D/g, '');
                if (p1.length > 2) {
                    month = '';
                    year = p1;
                } else {
                    month = p1;
                }
            }

            if (isSeparatorInsert) {
                const rawBeforeCursor = rawVal.substring(0, cursorPos);
                const sepCount = (rawBeforeCursor.match(/[.,\/ \-]/g) || []).length;
                if (sepCount === 1 && day.length === 1) {
                    day = '0' + day;
                } else if (sepCount === 2 && month.length === 1) {
                    month = '0' + month;
                }
            }

            if (day.length > 2) {
                const extra = day.substring(2);
                day = day.substring(0, 2);
                month = extra + month;
            }
            if (month.length > 2) {
                const extra = month.substring(2);
                month = month.substring(0, 2);
                year = extra + year;
            }
        } else {
            const digits = rawVal.replace(/\D/g, '');
            day = digits.substring(0, 2);
            month = digits.substring(2, 4);
            year = digits.substring(4, 8);
        }

        if (day.length === 2) {
            const dNum = parseInt(day, 10);
            if (dNum > 31) day = '31';
        }
        if (month.length === 2) {
            const mNum = parseInt(month, 10);
            if (mNum > 12) month = '12';
        }
        year = year.substring(0, 4);

        let formatted = '';
        if (day.length > 0) {
            formatted += day;
            if (day.length === 2 || hasSeparator || month.length > 0) {
                formatted += '.';
            }
        }
        if (month.length > 0 || (formatted.endsWith('.') && (year.length > 0 || (hasSeparator && rawVal.split(/[.,\/ \-]/).length > 2)))) {
            formatted += month;
            if (month.length === 2 || year.length > 0 || (hasSeparator && rawVal.split(/[.,\/ \-]/).length > 2)) {
                formatted += '.';
            }
        }
        if (year.length > 0) {
            formatted += year;
        }

        let newCursor = cursorPos;
        if (lastInputType === 'insertText' && !isSeparatorInsert) {
            if (cursorPos === 2 && day.length === 2 && formatted.length >= 3 && formatted[2] === '.') {
                newCursor = 3;
            } else if (cursorPos === 5 && month.length === 2 && formatted.length >= 6 && formatted[5] === '.') {
                newCursor = 6;
            }
        }

        if (newCursor > formatted.length) {
            newCursor = formatted.length;
        }

        if (el.value !== formatted) {
            el.value = formatted;
            el.setSelectionRange(newCursor, newCursor);
            const syntheticEvent = new Event('input', { bubbles: true });
            syntheticEvent._fromDateMask = true;
            el.dispatchEvent(syntheticEvent);
        } else {
            el.setSelectionRange(newCursor, newCursor);
        }

        lastInputType = '';
    });

    el.addEventListener('blur', () => {
        const val = el.value;
        if (!val) return;

        const parts = val.split('.');
        if (parts.length >= 1 && parts[0]) {
            let d = parseInt(parts[0], 10);
            if (!isNaN(d)) {
                if (d === 0) d = 1;
                if (d > 31) d = 31;
                parts[0] = String(d).padStart(2, '0');
            }
        }
        if (parts.length >= 2 && parts[1]) {
            let m = parseInt(parts[1], 10);
            if (!isNaN(m)) {
                if (m === 0) m = 1;
                if (m > 12) m = 12;
                parts[1] = String(m).padStart(2, '0');
            }
        }
        const formatted = parts.join('.');
        if (el.value !== formatted) {
            el.value = formatted;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

// Selecting all elements with the 'datepicker-input' class
document.addEventListener('DOMContentLoaded', () => {
    function initDatepickers() {
        document.querySelectorAll('.datepicker-input').forEach((datepickerEl) => {
            if (datepickerEl._datepicker_initialized) {
                if (!datepickerEl.hasAttribute('data-initialized')) {
                    datepickerEl.setAttribute('data-initialized', 'true');
                }
                return;
            }
            datepickerEl._datepicker_initialized = true;

            Datepicker.locales.uk = uk.uk;

            const minDate = datepickerEl.getAttribute('datepicker-min-date') || null;
            const maxDate = datepickerEl.getAttribute('datepicker-max-date') || null;
            const format = datepickerEl.getAttribute('datepicker-format') || 'dd.mm.yyyy';

            const shouldAutoSelectToday = datepickerEl.hasAttribute('datepicker-autoselect-today');
            const [yyyy, mm, dd] = new Date().toISOString().split('T')[0].split('-');
            const todayDate = format.replace('dd', dd).replace('mm', mm).replace('yyyy', yyyy);

            if (shouldAutoSelectToday && !datepickerEl.value) {
                datepickerEl.value = todayDate;
                datepickerEl.dispatchEvent(new InputEvent('input', {
                    bubbles: true,
                    composed: true
                }));
            }

            new Datepicker(datepickerEl, {
                defaultViewDate: datepickerEl.value,
                minDate: minDate,
                maxDate: maxDate,
                format: format,
                language: 'uk',
                autohide: true,
                showOnFocus: true
            });

            datepickerEl.setAttribute('data-initialized', 'true');
            datepickerEl.addEventListener('changeDate', () => {
                const inputEvent = new InputEvent('input', {
                    bubbles: true,
                    composed: true
                });
                inputEvent._fromChangeDate = true;
                datepickerEl.dispatchEvent(inputEvent);
            });

            attachDateMask(datepickerEl);
        });
    }

    // Prevent floating label from jumping when clicking inside the datepicker
    document.addEventListener('mousedown', (event) => {
        const activeInput = document.activeElement;
        const isClickInsideDatepicker = event.target.closest('.datepicker, .flatpickr-calendar');
        if (activeInput?.classList?.contains('datepicker-input') && isClickInsideDatepicker) {
            event.preventDefault();
        }
    });

    ['click', 'pointerdown', 'mousedown', 'mouseup'].forEach(evt => {
        document.body.addEventListener(evt, (event) => {
            if (event.target.closest('.datepicker, .flatpickr-calendar')) {
                event.stopPropagation();
            }
        });
    });

    function initDefaultDatepickerMasks() {
        document.querySelectorAll('.default-datepicker:not([data-date-mask])').forEach((el) => {
            attachDateMask(el);
        });
    }

    initDatepickers();
    initDefaultDatepickerMasks();

    const observer = new MutationObserver(() => {
        initDatepickers();
        initDefaultDatepickerMasks();
    });
    observer.observe(document.body, { childList: true, subtree: true });
});



let activeRequests = 0;
let isNavigating = false;

function updatePreloader() {
    const preloader = document.getElementById('preloader');
    if (!preloader) return;
    if (activeRequests > 0 || isNavigating) {
        preloader.style.setProperty('display', 'block', 'important');
    } else {
        preloader.style.setProperty('display', 'none', 'important');
    }
}

document.addEventListener('livewire:navigate', () => {
    isNavigating = true;
    updatePreloader();
});

document.addEventListener('livewire:navigating', () => {
    isNavigating = true;
    updatePreloader();
});

document.addEventListener('livewire:navigated', () => {
    isNavigating = false;
    activeRequests = 0; // Reset active requests on navigation
    updatePreloader();
});

window.addEventListener('beforeunload', () => {
    isNavigating = true;
    updatePreloader();
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        isNavigating = false;
        activeRequests = 0;
        updatePreloader();
    }
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('a');
    if (!link) return;

    const href = link.getAttribute('href');
    if (!href) return;

    if (
        href.startsWith('#') ||
        href.startsWith('javascript:') ||
        href.startsWith('mailto:') ||
        href.startsWith('tel:') ||
        link.hasAttribute('download') ||
        link.getAttribute('target') === '_blank' ||
        link.hasAttribute('wire:navigate')
    ) {
        return;
    }

    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    isNavigating = true;
    updatePreloader();
});

function registerLivewireHooks() {
    // JSON.stringify($wire) (Boost browser logger, DevTools, Alpine clones) looks up
    // toJSON on the proxy and Livewire POSTs it as a real method call. Drop those calls.
    Livewire.hook('commit', ({ commit }) => {
        if (!Array.isArray(commit.calls)) {
            return;
        }

        commit.calls = commit.calls.filter((call) => call.method !== 'toJSON');
    });

    Livewire.hook('request', ({ succeed, fail }) => {
        activeRequests++;
        updatePreloader();

        succeed(() => {
            activeRequests = Math.max(0, activeRequests - 1);
            updatePreloader();
        });

        fail(() => {
            activeRequests = Math.max(0, activeRequests - 1);
            updatePreloader();
        });
    });
}

if (window.Livewire) {
    registerLivewireHooks();
} else {
    document.addEventListener('livewire:init', registerLivewireHooks);
}

function scrollToElement(selector) {
    const element = document.querySelector(selector);
    if (element) {
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        // We also try to focus on the element if it's focusable (like an input).
        if (typeof element.focus === 'function') {
            element.focus();
        }
    }
}

document.addEventListener('livewire:init', () => {
    Livewire.on('employee-form-failed', (event) => {
        scrollToElement('.input-error, .select-error');
    });

    Livewire.on('scroll-to-element', (event) => {
        const selector = event.selector || (event.detail && event.detail.selector) || null;
        if (selector) {
            scrollToElement(selector);
        }
    });
});

function initThemeToggle() {
    const theme = localStorage.getItem('color-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    if (theme === 'dark' || (!theme && prefersDark)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

// After Livewire SPA navigation
document.addEventListener('livewire:navigated', () => {
    initThemeToggle();
});

import.meta.glob([
    '../images/**'
], { eager: true });

import flatpickr from "flatpickr";
import "flatpickr/dist/flatpickr.css";
import { Ukrainian } from "flatpickr/dist/l10n/uk.js";

function initUkTimepickers(root = document) {
    const inputs = root.querySelectorAll('input.timepicker-uk');

    inputs.forEach((el) => {
        if (el._tp_initialized) {
            if (!el.hasAttribute('data-tp-initialized')) {
                el.setAttribute('data-tp-initialized', 'true');
            }
            return;
        }
        el._tp_initialized = true;

        el.setAttribute('data-tp-initialized', 'true');
        el.setAttribute('placeholder', '--:--');
        el.setAttribute('maxlength', '5');
        el.setAttribute('autocomplete', 'off');

        function formatTime(digits) {
            if (digits.length === 0) return '';
            if (digits.length <= 2) return digits;
            return digits.substring(0, 2) + ':' + digits.substring(2);
        }

        function validateDigits(val) {
            if (val.length >= 1 && parseInt(val[0], 10) > 2) {
                val = '0' + val;
            }
            if (val.length >= 2 && parseInt(val.substring(0, 2), 10) > 23) {
                val = '23' + val.substring(2);
            }
            if (val.length >= 3 && parseInt(val[2], 10) > 5) {
                val = val.substring(0, 2) + '0' + val[2] + val.substring(3);
            }
            if (val.length >= 4 && parseInt(val.substring(2, 4), 10) > 59) {
                val = val.substring(0, 2) + '59';
            }
            return val.substring(0, 4);
        }

        el.addEventListener('keydown', (e) => {
            const isDigit = /^\d$/.test(e.key);
            const isNav   = ['ArrowLeft', 'ArrowRight', 'Tab', 'Home', 'End'].includes(e.key);

            if (!isDigit && !isNav && e.key !== 'Backspace' && e.key !== 'Delete') {
                if ((e.ctrlKey || e.metaKey) && e.key === 'a') return;
                e.preventDefault();
                return;
            }

            if (isNav) return;

            e.preventDefault();

            const selStart = el.selectionStart;
            const selEnd   = el.selectionEnd;
            const hasSelection = selStart !== selEnd;

            if (hasSelection) {
                if (e.key === 'Backspace' || e.key === 'Delete') {
                    const dStart = selStart >= 3 ? selStart - 1 : selStart;
                    const dEnd   = selEnd   >= 3 ? selEnd   - 1 : selEnd;
                    let val = el.value.replace(/\D/g, '');
                    val = val.substring(0, dStart) + val.substring(dEnd);
                    val = validateDigits(val);
                    const formatted = formatTime(val);
                    el.value = formatted;
                    let newCur = dStart;
                    if (newCur >= 2 && formatted.length > 2) newCur++;
                    newCur = Math.min(newCur, formatted.length);
                    el.setSelectionRange(newCur, newCur);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                    return;
                }

                if (isDigit) {
                    const dStart = selStart >= 3 ? selStart - 1 : selStart;
                    const dEnd   = selEnd   >= 3 ? selEnd   - 1 : selEnd;
                    let val = el.value.replace(/\D/g, '');
                    val = val.substring(0, dStart) + e.key + val.substring(dEnd);
                    val = validateDigits(val);
                    const formatted = formatTime(val);
                    el.value = formatted;
                    let newCur = dStart + 1;
                    if (newCur >= 2 && formatted.length > 2) newCur++;
                    newCur = Math.min(newCur, formatted.length);
                    el.setSelectionRange(newCur, newCur);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                    return;
                }
            }

            let val = el.value.replace(/\D/g, '');
            const cur = selStart;
            let digitPos = cur >= 3 ? cur - 1 : cur;

            if (e.key === 'Backspace') {
                if (digitPos > 0) {
                    val = val.substring(0, digitPos - 1) + val.substring(digitPos);
                }
                val = validateDigits(val);
                const formatted = formatTime(val);
                el.value = formatted;

                let newCur = digitPos - 1;
                if (newCur >= 2 && formatted.length > 2) newCur++;
                el.setSelectionRange(Math.max(0, newCur), Math.max(0, newCur));

            } else if (e.key === 'Delete') {
                if (digitPos < val.length) {
                    val = val.substring(0, digitPos) + val.substring(digitPos + 1);
                }
                val = validateDigits(val);
                const formatted = formatTime(val);
                el.value = formatted;

                let newCur = digitPos;
                if (newCur >= 2 && formatted.length > 2) newCur++;
                el.setSelectionRange(Math.min(newCur, formatted.length), Math.min(newCur, formatted.length));

            } else {
                if (val.length >= 4) {
                    val = val.substring(0, digitPos) + e.key + val.substring(digitPos + 1);
                } else {
                    val = val.substring(0, digitPos) + e.key + val.substring(digitPos);
                }
                val = validateDigits(val);
                const formatted = formatTime(val);
                el.value = formatted;

                let newCur = digitPos + 1;
                if (newCur >= 2 && formatted.length > 2) newCur++; // jump over ':'
                if (newCur > formatted.length) newCur = formatted.length;
                el.setSelectionRange(newCur, newCur);
            }

            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });

        el.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            let val = pasted.replace(/\D/g, '');
            val = validateDigits(val);
            el.value = formatTime(val);
            el.setSelectionRange(el.value.length, el.value.length);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}

function initUkDateRangePickers(root = document) {
    const inputs = root.querySelectorAll('input.daterangepicker-uk');

    inputs.forEach((el) => {
        if (el._flatpickr) {
            if (!el.hasAttribute('data-drp-initialized')) {
                el.setAttribute('data-drp-initialized', 'true');
            }
            return;
        }

        flatpickr(el, {
            mode: "range",
            showMonths: 2,
            dateFormat: "d.m.Y",
            locale: {
                ...Ukrainian,
                rangeSeparator: " — "
            },
            allowInput: true,
            onChange: (selectedDates, dateStr, instance) => {
                el.value = dateStr;
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
        el.setAttribute('data-drp-initialized', 'true');
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initUkTimepickers();
    initUkDateRangePickers();
    const tpObserver = new MutationObserver(() => {
        initUkTimepickers();
        initUkDateRangePickers();
    });
    tpObserver.observe(document.body, { childList: true, subtree: true });
});

if (window.Livewire) {
    document.addEventListener("livewire:load", () => {
        Livewire.hook("message.processed", (message, component) => {
            initUkTimepickers(component?.el || document);
            initUkDateRangePickers(component?.el || document);
            initFlowbite();
        });
    });

    document.addEventListener("livewire:updated", () => {
        initFlowbite();
    });

    document.addEventListener("livewire:navigated", () => {
        initUkTimepickers();
        initUkDateRangePickers();
        initFlowbite();
    });
}
