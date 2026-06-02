import { initFlowbite } from 'flowbite';
import 'flowbite';
import './bootstrap';
import './common';
import './index';

import Datepicker from 'flowbite-datepicker/Datepicker';
import uk from '../../node_modules/flowbite-datepicker/js/i18n/locales/uk.js';

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

            datepickerEl.setAttribute('data-initialized', 'true'); // Avoidance of reinitialisation
            datepickerEl.addEventListener('changeDate', () => {
                const inputEvent = new InputEvent('input', {
                    bubbles: true,
                    composed: true
                });
                datepickerEl.dispatchEvent(inputEvent);
            });
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

    // Call when the page loads
    initDatepickers();

    // Monitor changes in the DOM (if new datepickers are added)
    const observer = new MutationObserver(() => {
        initDatepickers();
    });
    observer.observe(document.body, { childList: true, subtree: true });
});

document.addEventListener('livewire:load', () => {
    Livewire.hook('message.sent', (message) => {
        if (message.actionQueue[0].payload.method === 'update') {
            document.getElementById('preloader').style.display = 'block';
        }
    });

    Livewire.hook('message.processed', (message) => {
        if (message.actionQueue[0].payload.method === 'update') {
            document.getElementById('preloader').style.display = 'none';
        }
    });
});

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

function initUkDatepickers(root = document) {
    const inputs = root.querySelectorAll('input.datepicker-flatpickr:not([data-df-initialized])');

    inputs.forEach((el) => {
        if (el._flatpickr) return;

        const maxDate = el.getAttribute('datepicker-max-date') || null;
        const minDate = el.getAttribute('datepicker-min-date') || null;

        flatpickr(el, {
            allowInput: true,
            dateFormat: "d.m.Y",
            locale: Ukrainian,
            maxDate: maxDate,
            minDate: minDate,
            onChange: (selectedDates, dateStr, instance) => {
                el.value = dateStr;
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
            },
            onClose: (selectedDates, dateStr, instance) => {
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });

        el.setAttribute('data-df-initialized', 'true');

        el.addEventListener('blur', () => {
            el.dispatchEvent(new Event("input", { bubbles: true }));
            el.dispatchEvent(new Event("change", { bubbles: true }));
        });

        el.addEventListener('input', (e) => {
            if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
                return;
            }

            let val = e.target.value.replace(/\D/g, '');
            if (val.length === 0) {
                e.target.value = '';
                return;
            }

            let d = '';
            let m = '';
            let y = '';

            if (val.length >= 1) {
                if (parseInt(val[0]) > 3) {
                    val = '0' + val;
                }
            }

            if (val.length >= 2) {
                d = val.substring(0, 2);
                if (parseInt(d) > 31) d = '31';
                if (parseInt(d) === 0) d = '01';
            } else {
                d = val;
            }

            if (val.length >= 3) {
                let mPart = val.substring(2);
                if (parseInt(mPart[0]) > 1) {
                    val = val.substring(0, 2) + '0' + mPart;
                }
            }

            if (val.length >= 4) {
                m = val.substring(2, 4);
                if (parseInt(m) > 12) m = '12';
                if (parseInt(m) === 0) m = '01';
            } else if (val.length > 2) {
                m = val.substring(2);
            }

            if (val.length >= 5) {
                y = val.substring(4, 8);
            }

            let res = d;
            if (val.length >= 2) {
                res += '.';
            }
            if (m.length > 0) {
                res += m;
            }
            if (val.length >= 4) {
                res += '.';
            }
            if (y.length > 0) {
                res += y;
            }

            e.target.value = res;

            if (res.length === 10 && el._flatpickr) {
                const parsed = el._flatpickr.parseDate(res, "d.m.Y");
                if (parsed) {
                    el._flatpickr.setDate(parsed, false);
                }
            }
        });
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initUkTimepickers();
    initUkDatepickers();
    const tpObserver = new MutationObserver(() => {
        initUkTimepickers();
        initUkDatepickers();
    });
    tpObserver.observe(document.body, { childList: true, subtree: true });
});

if (window.Livewire) {
    document.addEventListener("livewire:load", () => {
        Livewire.hook("message.processed", (message, component) => {
            initUkTimepickers(component?.el || document);
            initUkDatepickers(component?.el || document);
            initFlowbite();
        });
    });

    document.addEventListener("livewire:updated", () => {
        initFlowbite();
    });

    document.addEventListener("livewire:navigated", () => {
        initUkTimepickers();
        initUkDatepickers();
        initFlowbite();
    });
}
