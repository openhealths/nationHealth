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
            const val = el.value;
            if (pos > 0 && val[pos - 1] === '.') {
                e.preventDefault();
                const before = val.substring(0, Math.max(pos - 2, 0));
                const after = val.substring(pos);
                el.value = before + after;
                const newPos = Math.max(pos - 2, 0);
                el.setSelectionRange(newPos, newPos);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }
        }
    });

    el.addEventListener('input', (e) => {
        if (e._fromDateMask || e._fromChangeDate) return;

        const cursorPos = el.selectionStart;
        const oldVal = el.value;

        const isSeparatorInsert = (
            lastInputType === 'insertText' &&
            e.data && /^[.,\/ \-]$/.test(e.data)
        );

        let digits = oldVal.replace(/\D/g, '');

        if (isSeparatorInsert && digits.length > 0) {
            const rawBeforeCursor = oldVal.substring(0, cursorPos);
            const digitsBeforeCursor = rawBeforeCursor.replace(/\D/g, '').length;

            if (digitsBeforeCursor === 1) {
                digits = '0' + digits;
            } else if (digitsBeforeCursor === 3) {
                digits = digits.substring(0, 2) + '0' + digits.substring(2);
            }
        }

        if (digits.length >= 1) {
            const d1 = parseInt(digits[0], 10);
            if (d1 >= 4 && d1 <= 9) digits = '0' + digits;
        }

        if (digits.length >= 2) {
            let day = parseInt(digits.substring(0, 2), 10);
            if (day > 31) day = 31;
            if (day === 0) day = 1;
            digits = String(day).padStart(2, '0') + digits.substring(2);
        }

        if (digits.length >= 3) {
            const m1 = parseInt(digits[2], 10);
            if (m1 >= 2 && m1 <= 9) digits = digits.substring(0, 2) + '0' + digits.substring(2);
        }

        if (digits.length >= 4) {
            let month = parseInt(digits.substring(2, 4), 10);
            if (month > 12) month = 12;
            if (month === 0) month = 1;
            digits = digits.substring(0, 2) + String(month).padStart(2, '0') + digits.substring(4);
        }

        digits = digits.substring(0, 8);

        let formatted = '';
        if (digits.length <= 2) {
            formatted = digits;
        } else if (digits.length <= 4) {
            formatted = digits.substring(0, 2) + '.' + digits.substring(2);
        } else {
            formatted = digits.substring(0, 2) + '.' + digits.substring(2, 4) + '.' + digits.substring(4);
        }

        const rawBefore = oldVal.substring(0, cursorPos);
        let digitsBefore = rawBefore.replace(/\D/g, '').length;

        if (digits.length > oldVal.replace(/\D/g, '').length) {
            const padCount = digits.length - oldVal.replace(/\D/g, '').length;
            if (isSeparatorInsert) {
                digitsBefore += padCount;
            } else if (digitsBefore <= 1 && parseInt(oldVal.replace(/\D/g, '')[0], 10) >= 4) {
                digitsBefore += 1;
            } else if (digitsBefore >= 3 && digits.length >= 4) {
                const oldDigits = oldVal.replace(/\D/g, '');
                if (oldDigits.length >= 3 && parseInt(oldDigits[2], 10) >= 2) {
                    digitsBefore += 1;
                }
            }
        }

        let newCursorPos = 0;
        let digitCount = 0;
        for (let i = 0; i < formatted.length; i++) {
            if (formatted[i] !== '.') digitCount++;
            newCursorPos = i + 1;
            if (digitCount >= digitsBefore) break;
        }

        if (newCursorPos < formatted.length && formatted[newCursorPos] === '.') {
            newCursorPos++;
        }

        if (el.value !== formatted) {
            el.value = formatted;
            el.setSelectionRange(newCursorPos, newCursorPos);
            const syntheticEvent = new Event('input', { bubbles: true });
            syntheticEvent._fromDateMask = true;
            el.dispatchEvent(syntheticEvent);
        } else {
            el.setSelectionRange(newCursorPos, newCursorPos);
        }

        lastInputType = '';
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
    console.log('[Preloader] updatePreloader called:', {
        preloaderExists: !!preloader,
        activeRequests,
        isNavigating
    });
    if (!preloader) return;
    if (activeRequests > 0 || isNavigating) {
        console.log('[Preloader] Showing spinner');
        preloader.style.setProperty('display', 'block', 'important');
    } else {
        console.log('[Preloader] Hiding spinner');
        preloader.style.setProperty('display', 'none', 'important');
    }
}

document.addEventListener('livewire:navigate', () => {
    console.log('[Preloader] livewire:navigate event');
    isNavigating = true;
    updatePreloader();
});

document.addEventListener('livewire:navigating', () => {
    console.log('[Preloader] livewire:navigating event');
    isNavigating = true;
    updatePreloader();
});

document.addEventListener('livewire:navigated', () => {
    console.log('[Preloader] livewire:navigated event');
    isNavigating = false;
    activeRequests = 0; // Reset active requests on navigation
    updatePreloader();
});

window.addEventListener('beforeunload', () => {
    console.log('[Preloader] beforeunload event');
    isNavigating = true;
    updatePreloader();
});

window.addEventListener('pageshow', (event) => {
    console.log('[Preloader] pageshow event:', event.persisted);
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

    console.log('[Preloader] Normal link click transition detected:', href);
    isNavigating = true;
    updatePreloader();
});

function registerLivewireHooks() {
    console.log('[Preloader] registering Livewire hooks');
    Livewire.hook('request', ({ succeed, fail }) => {
        console.log('[Preloader] request hook: request started');
        activeRequests++;
        updatePreloader();

        succeed(() => {
            console.log('[Preloader] request hook: request succeeded');
            activeRequests = Math.max(0, activeRequests - 1);
            updatePreloader();
        });

        fail(() => {
            console.log('[Preloader] request hook: request failed');
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

document.addEventListener("DOMContentLoaded", () => {
    initUkTimepickers();
    const tpObserver = new MutationObserver(() => {
        initUkTimepickers();
    });
    tpObserver.observe(document.body, { childList: true, subtree: true });
});

if (window.Livewire) {
    document.addEventListener("livewire:load", () => {
        Livewire.hook("message.processed", (message, component) => {
            initUkTimepickers(component?.el || document);
            initFlowbite();
        });
    });

    document.addEventListener("livewire:updated", () => {
        initFlowbite();
    });

    document.addEventListener("livewire:navigated", () => {
        initUkTimepickers();
        initFlowbite();
    });
}
