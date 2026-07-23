import { initFlowbite } from 'flowbite';
import 'flowbite';
import './bootstrap';
import './common';
import './index';

import Datepicker from 'flowbite-datepicker/Datepicker';
import uk from '../../node_modules/flowbite-datepicker/js/i18n/locales/uk.js';

// Date input mask DD.MM.YYYY
function attachDateMask(el) {
    if (el.hasAttribute('data-date-mask')) return;
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
        document.querySelectorAll('.datepicker-input:not([data-initialized])').forEach((datepickerEl) => {
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
        const isClickInsideDatepicker = event.target.closest('.datepicker');
        if (activeInput?.classList?.contains('datepicker-input') && isClickInsideDatepicker) {
            event.preventDefault();
        }
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
]);

import flatpickr from "flatpickr";
import "flatpickr/dist/flatpickr.css";
import { Ukrainian } from "flatpickr/dist/l10n/uk.js";

function initUkTimepickers(root = document) {
    const inputs = root.querySelectorAll('input.timepicker-uk:not([data-tp-initialized])');

    inputs.forEach((el) => {
        if (el._flatpickr) return;

        flatpickr(el, {
            enableTime: true,
            noCalendar: true,
            time_24hr: true,
            dateFormat: "H:i",
            allowInput: true,
            locale: Ukrainian,

            onChange: (selectedDates, dateStr, instance) => {
                const v = selectedDates[0]
                    ? instance.formatDate(selectedDates[0], "H:i")
                    : dateStr;
                el.value = v || "";
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
            },

            onClose: (selectedDates, dateStr, instance) => {
                if (!dateStr) return;
                try {
                    const [h, m] = dateStr.split(":").map((x) => x.trim());
                    if (
                        h !== undefined &&
                        m !== undefined &&
                        /^\d{1,2}$/.test(h) &&
                        /^\d{1,2}$/.test(m)
                    ) {
                        const hh = String(Math.min(Math.max(parseInt(h, 10), 0), 23)).padStart(2, "0");
                        const mm = String(Math.min(Math.max(parseInt(m, 10), 0), 59)).padStart(2, "0");
                        const norm = `${hh}:${mm}`;
                        if (norm !== el.value) {
                            el._flatpickr.setDate(norm, false, "H:i");
                            el.value = norm;
                            el.dispatchEvent(new Event("input", { bubbles: true }));
                            el.dispatchEvent(new Event("change", { bubbles: true }));
                        }
                    }
                } catch (_) {}
            },
        });
        el.setAttribute('data-tp-initialized', 'true');
        el.addEventListener('input', (e) => {
            if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
                e.target.value = e.target.value.replace(/[^\d:]/g, '').slice(0, 5);
                return;
            }

            let val = e.target.value.replace(/\D/g, '');
            if (val.length === 0) {
                e.target.value = '';
                return;
            }

            let h = '';
            let m = '';

            if (val.length >= 1) {
                if (parseInt(val[0]) > 2) {
                    val = '0' + val;
                }
            }

            if (val.length >= 2) {
                h = val.substring(0, 2);
                if (parseInt(h) > 23) h = '23';
            } else {
                h = val;
            }

            if (val.length >= 3) {
                m = val.substring(2, 4);
                if (parseInt(m[0]) > 5) {
                    m = '0' + m[0];
                }
                if (val.length >= 4 && parseInt(m) > 59) {
                    m = '59';
                }
            }

            let res = h;
            if (val.length >= 2) {
                res += ':';
            }
            if (m.length > 0) {
                res += m;
            }

            e.target.value = res;
        });
    });
}

function initUkDateRangePickers(root = document) {
    const inputs = root.querySelectorAll('input.daterangepicker-uk:not([data-drp-initialized])');

    inputs.forEach((el) => {
        if (el._flatpickr) return;

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
