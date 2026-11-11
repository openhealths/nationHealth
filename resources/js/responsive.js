// Keep dropdowns visible when a table fits; contain horizontal scrolling when it does not.
// Reuse parents containing only a table, without changing the DOM managed by Livewire.
const selector = '.table-scroll, .index-table-wrapper, .table-container, .show-fieldset-table-wrapper, '
    + ':is(.responsive-content, .modal-content) div:has(> table.table-input:only-child)';
const tables = new Map();
const tableWrappers = new WeakMap();
const dirty = new Set();
const calendars = new Map();
const viewport = window.matchMedia('(min-width: 640px)');
const compact = window.matchMedia('(width < 1024px)');
let scheduled = false;
let refresh = false;

function resizeCalendar(instance) {
    // Flatpickr retains its inline width when switching from two months to one.
    instance.calendarContainer.style.width = '';
    instance.set('showMonths', viewport.matches ? 2 : 1);
}

function findCalendars() {
    for (const [input, instance] of calendars) {
        if (!input.isConnected || input._flatpickr !== instance) calendars.delete(input);
    }
    document.querySelectorAll(':is(.responsive-content, .modal-content) input.daterangepicker-uk').forEach((input) => {
        if (!input._flatpickr || calendars.has(input)) return;
        calendars.set(input, input._flatpickr);
        resizeCalendar(input._flatpickr);
    });
}

function removeTable(wrapper, { table, contents }) {
    sizes.unobserve(wrapper);
    sizes.unobserve(table);
    contents.disconnect();
    tableWrappers.delete(table);
    tables.delete(wrapper);
    dirty.delete(wrapper);
    wrapper.classList.remove('table-scroll-fits');
}

function findTables() {
    for (const [wrapper, entry] of tables) {
        if (!wrapper.isConnected || !wrapper.matches(selector)
            || wrapper.querySelector(':scope > table') !== entry.table) {
            removeTable(wrapper, entry);
        }
    }
    document.querySelectorAll(selector).forEach((wrapper) => {
        const table = wrapper.querySelector(':scope > table');
        if (!table) return;
        if (!tables.has(wrapper)) {
            // Alpine can change table rows without a Livewire request.
            // Ignore unrelated search, preloader and calendar DOM changes.
            const contents = new MutationObserver(() => {
                dirty.add(wrapper);
                schedule();
            });
            contents.observe(table, { childList: true, subtree: true, characterData: true });
            tables.set(wrapper, { table, contents });
            tableWrappers.set(table, wrapper);
            sizes.observe(wrapper);
            sizes.observe(table);
        }
        dirty.add(wrapper);
    });
}

function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
        scheduled = false;
        if (!compact.matches) {
            for (const [wrapper, entry] of tables) removeTable(wrapper, entry);
            for (const [input, instance] of calendars) {
                if (input.isConnected && input._flatpickr === instance) resizeCalendar(instance);
            }
            calendars.clear();
            refresh = false;
            return;
        }
        if (refresh) {
            refresh = false;
            findCalendars();
            findTables();
        }
        // Read sizes first, then update classes without interleaving layout writes.
        const measurements = [];
        for (const wrapper of dirty) {
            const entry = tables.get(wrapper);
            if (!entry) continue;
            if (!wrapper.isConnected) {
                removeTable(wrapper, entry);
                continue;
            }
            measurements.push([wrapper, entry.table.scrollWidth <= wrapper.clientWidth + 1]);
        }
        dirty.clear();
        for (const [wrapper, fits] of measurements) wrapper.classList.toggle('table-scroll-fits', fits);
    });
}

function refreshContent() {
    refresh = true;
    schedule();
}

const sizes = new ResizeObserver((entries) => {
    for (const { target } of entries) {
        const wrapper = tables.has(target) ? target : tableWrappers.get(target);
        if (wrapper) dirty.add(wrapper);
    }
    if (dirty.size) schedule();
});

let hooksRegistered = false;
function registerLivewireHooks() {
    if (hooksRegistered || !window.Livewire) return;
    hooksRegistered = true;
    // Discover/recheck tables once after the component's entire morph.
    Livewire.hook('morphed', refreshContent);
}

function start() {
    viewport.addEventListener('change', () => {
        if (!compact.matches) return;
        findCalendars();
        for (const instance of calendars.values()) resizeCalendar(instance);
    });
    compact.addEventListener('change', refreshContent);
    document.addEventListener('livewire:navigated', refreshContent);
    document.addEventListener('livewire:init', registerLivewireHooks, { once: true });
    registerLivewireHooks();
    if (document.querySelector('.responsive-content')) refreshContent();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
