import { referralIndex, referralDropdown } from './referral-index';
import ui from '@alpinejs/ui';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('referralIndex', referralIndex);
    window.Alpine.data('referralDropdown', referralDropdown);
    window.Alpine.plugin(ui);

    /**
     * ICD-10 code search against the URL in the root element's `data-icd10-search-url`.
     * A new search cancels the previous request, so only the latest term's codes are shown.
     * While `searching` is on, the global preloader is shown.
     */
    window.Alpine.data('icd10Search', () => ({
        results: [],
        showResults: false,
        searching: false,
        searchRequest: null,
        timer: null,

        init() {
            this.$watch('searching', (isSearching) => {
                window.dispatchEvent(new CustomEvent(isSearching ? 'preloader:start' : 'preloader:stop'));
            });
        },

        destroy() {
            clearTimeout(this.timer);
            this.searchRequest?.abort();
            if (this.searching) {
                window.dispatchEvent(new CustomEvent('preloader:stop'));
            }
        },

        cancelSearch() {
            clearTimeout(this.timer);
            this.searchRequest?.abort();
            this.searchRequest = null;
            this.results = [];
            this.showResults = false;
            this.searching = false;
        },

        queueSearch(value) {
            this.cancelSearch();
            this.timer = setTimeout(() => this.search(value), 600);
        },

        search(value) {
            this.searchRequest?.abort();
            this.showResults = false;
            this.searching = false;

            const isLatin = /^[a-zA-Z0-9.]+$/.test(value);
            if (value.length < (isLatin ? 1 : 3)) {
                return;
            }

            const searchUrl = new URL(this.$root.dataset.icd10SearchUrl);
            searchUrl.searchParams.set('search', value);
            const searchRequest = new AbortController();
            this.searchRequest = searchRequest;
            this.searching = true;

            fetch(searchUrl, {
                headers: { Accept: 'application/json' },
                signal: searchRequest.signal,
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((foundCodes) => {
                    if (this.searchRequest !== searchRequest || searchRequest.signal.aborted) {
                        return;
                    }
                    this.results = foundCodes;
                    this.showResults = true;
                })
                .catch((error) => {
                    if (error.name !== 'AbortError') {
                        throw error;
                    }
                })
                .finally(() => {
                    if (this.searchRequest === searchRequest) {
                        this.searching = false;
                    }
                });
        },
    }));
});

// Native dropdown presentation shares the existing UI entry point.
const dropdowns = new Map();
const chevron = '<svg class="dropdown-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m5.5 8 4.5 4.5L14.5 8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const cross = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m6 6 8 8m0-8-8 8" stroke-linecap="round"/></svg>';
let active;
let initialized = false;
let queued = false;
let sequence = 0;

const placeholder = (option) => !option || option.value === '' || (option.hidden && option.value === '_placeholder_');
const attributes = (element, values) => Object.entries(values).forEach(([name, value]) => {
    if (value == null) {
        if (element.hasAttribute(name)) element.removeAttribute(name);
    } else if (element.getAttribute(name) !== String(value)) element.setAttribute(name, value);
});

class Dropdown {
    constructor(element) {
        this.element = element;
        this.isSelect = element.tagName === 'SELECT';
        this.uid = `dropdown-${++sequence}`;
        this.listId = element.getAttribute('list');
        this.source = this.isSelect ? element : document.getElementById(this.listId);
        this.original = Object.fromEntries(['tabindex', 'aria-hidden', 'role', 'aria-autocomplete', 'autocomplete', 'aria-invalid', 'aria-describedby', 'aria-controls', 'aria-expanded', 'aria-activedescendant'].map((name) => [name, element.getAttribute(name)]));
        this.hadFieldClass = element.classList.contains('dropdown-field');
        this.controller = new AbortController();
        this.restorers = [];
        this.hookedOptions = new WeakSet();
        this.entries = [];
        this.activeIndex = -1;
        this.control = document.createElement('div');
        this.control.className = 'dropdown-control';
        this.control.dataset.dropdownUi = '';
        this.control.setAttribute('wire:ignore', '');
        this.control.innerHTML = (this.isSelect ? '<button type="button" role="combobox" aria-expanded="false"><span class="dropdown-value"></span></button>' : '')
            + `<button type="button" class="dropdown-clear" aria-label="Очистити вибір" hidden>${cross}</button>${chevron}`;
        this.trigger = this.isSelect ? this.control.firstElementChild : element;
        this.trigger.id ||= this.uid;
        this.clear = this.control.querySelector('.dropdown-clear');
        element.after(this.control);
        if (this.isSelect) {
            element.classList.add('dropdown-native');
            attributes(element, { tabindex: '-1', 'aria-hidden': 'true' });
        } else {
            element.removeAttribute('list');
            element.classList.add('dropdown-field');
            attributes(element, { role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false', autocomplete: 'off' });
            this.control.style.pointerEvents = 'none';
            this.clear.style.pointerEvents = 'auto';
            if (getComputedStyle(element.parentElement).position === 'static') {
                this.parentPosition = element.parentElement.style.position;
                element.parentElement.style.position = 'relative';
            }
        }
        const listen = (target, type, handler, options = {}) => target.addEventListener(type, handler, { ...options, signal: this.controller.signal });
        listen(this.trigger, 'click', (event) => {
            if (this.isSelect) event.preventDefault();
            this.trigger.focus();
            if (this.menu && this.isSelect) this.close();
            else this.open(true);
        });
        listen(this.trigger, 'keydown', (event) => this.keydown(event));
        listen(this.clear, 'click', (event) => {
            event.stopPropagation();
            this.commit([]);
            this.close();
            this.trigger.focus();
        });
        listen(this.clear, 'mousedown', (event) => event.preventDefault());
        listen(element, 'input', () => {
            this.refresh();
            if (!this.isSelect) this.open();
        });
        listen(element, 'change', () => this.refresh());
        listen(element, 'focus', () => this.isSelect ? this.trigger.focus() : this.open());
        if (this.isSelect) listen(this.control, 'focusout', (event) => this.blur(event));
        listen(element, 'invalid', (event) => this.invalid(event));

        this.observer = new MutationObserver(() => this.refresh());
        this.observer.observe(this.source, { attributes: true, childList: true, subtree: true, characterData: true });
        if (!this.isSelect) this.observer.observe(element, { attributes: true });
        this.observe(element, 'value', this.isSelect ? HTMLSelectElement.prototype : HTMLInputElement.prototype);
        if (this.isSelect) this.observe(element, 'selectedIndex', HTMLSelectElement.prototype);
        this.resize = new ResizeObserver(() => { this.align(); this.position(); });
        this.resize.observe(this.trigger);
        this.sync();
    }

    observe(element, property, prototype) {
        if (Object.hasOwn(element, property)) return;
        const descriptor = Object.getOwnPropertyDescriptor(prototype, property);
        if (!descriptor?.set || !descriptor?.get) return;
        const refresh = () => this.refresh();
        Object.defineProperty(element, property, {
            configurable: true,
            get() { return descriptor.get.call(this); },
            set(value) { descriptor.set.call(this, value); refresh(); },
        });
        this.restorers.push(() => delete element[property]);
    }

    refresh() {
        if (this.pending || this.destroyed) return;
        this.pending = true;
        queueMicrotask(() => { this.pending = false; if (!this.destroyed) this.sync(); });
    }

    align() {
        if (this.isSelect) return;
        Object.assign(this.control.style, { position: 'absolute', left: `${this.trigger.offsetLeft}px`, top: `${this.trigger.offsetTop}px`, width: `${this.trigger.offsetWidth}px`, height: `${this.trigger.offsetHeight}px` });
    }

    sync() {
        const element = this.element;
        if (this.validation && element.validity.valid) { this.validation.remove(); this.validation = null; }
        if (this.isSelect) {
            this.trigger.className = [...element.classList].filter((name) => name !== 'dropdown-native').join(' ') + ' dropdown-field';
            const selected = [...element.selectedOptions].filter((option) => !placeholder(option));
            const empty = [...element.options].find(placeholder);
            this.trigger.firstElementChild.textContent = selected.length ? selected.map((option) => option.label.trim()).join(', ')
                : element.getAttribute('placeholder') || empty?.label.trim() || 'Обрати';
            this.trigger.firstElementChild.classList.toggle('dropdown-placeholder', !selected.length);
            this.trigger.disabled = this.disabled;
            attributes(this.trigger, { style: element.getAttribute('style'), tabindex: this.original.tabindex ?? '0' });
            const labels = [...element.labels];
            if (!labels.length) {
                const label = element.parentElement?.querySelector(':scope > .label');
                if (label) labels.push(label);
            }
            labels.forEach((label, index) => { if (!label.id) label.id = `${this.uid}-label-${index}`; });
            attributes(this.trigger, { 'aria-labelledby': element.getAttribute('aria-labelledby') || (labels.length ? labels.map((label) => label.id).join(' ') : null), 'aria-label': labels.length ? null : element.getAttribute('aria-label') || element.name || 'Обрати', 'aria-required': String(element.required) });
            for (const option of element.options) {
                if (this.hookedOptions.has(option)) continue;
                this.hookedOptions.add(option);
                this.observe(option, 'selected', HTMLOptionElement.prototype);
            }
        }
        attributes(this.trigger, {
            'aria-invalid': String((this.isSelect ? element.getAttribute('aria-invalid') : this.original['aria-invalid']) === 'true' || element.classList.contains('input-error') || Boolean(this.validation && !element.validity.valid)),
            'aria-describedby': [this.isSelect ? element.getAttribute('aria-describedby') : this.original['aria-describedby'], this.validation?.id].filter(Boolean).join(' ') || null,
        });
        this.control.hidden = element.hidden || getComputedStyle(element).display === 'none';
        this.clear.hidden = this.disabled || (this.isSelect ? ![...element.selectedOptions].some((option) => !placeholder(option)) : !element.value);
        this.align();
        if (this.menu) {
            if (this.disabled || this.control.hidden || !this.trigger.getClientRects().length) this.close();
            else { this.render(); this.position(); }
        }
    }

    get disabled() {
        return this.element.matches(':disabled') || this.element.hasAttribute('readonly');
    }

    commit(options) {
        if (this.disabled) return;
        const before = this.isSelect && this.element.multiple ? [...this.element.selectedOptions].map((option) => option.index).join(',') : this.element.value;
        if (!this.isSelect) this.element.value = options[0]?.value || '';
        else if (this.element.multiple) [...this.element.options].forEach((option) => { option.selected = options.includes(option); });
        else if (options.length) this.element.selectedIndex = options[0].index;
        else {
            // Existing change handlers read options[selectedIndex], including when clearing.
            let empty = [...this.element.options].find((option) => option.value === '');
            if (!empty) {
                this.temporaryEmpty = document.createElement('option');
                this.temporaryEmpty.hidden = true;
                this.temporaryEmpty.value = '';
                this.temporaryEmpty.textContent = 'Обрати';
                this.element.append(this.temporaryEmpty);
                empty = this.temporaryEmpty;
            }
            this.element.selectedIndex = empty.index;
        }
        const after = this.isSelect && this.element.multiple ? [...this.element.selectedOptions].map((option) => option.index).join(',') : this.element.value;
        if (before !== after) {
            this.element.dispatchEvent(new Event('input', { bubbles: true }));
            this.element.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this.sync();
    }

    render() {
        const query = (this.search?.value ?? (this.isSelect ? '' : this.element.value)).trim().toLocaleLowerCase();
        const previous = this.entries[this.activeIndex]?.option;
        this.entries = [];
        this.list.replaceChildren();
        let lastGroup;
        for (const option of this.source.options) {
            const group = option.closest('optgroup');
            const text = this.isSelect ? option.label : option.value + ' ' + option.label;
            if (option.hidden || (this.isSelect && getComputedStyle(option).display === 'none') || group?.hidden || (group && getComputedStyle(group).display === 'none') || (query && !text.toLocaleLowerCase().includes(query))) continue;
            if (group && group !== lastGroup) {
                const heading = document.createElement('div');
                heading.className = 'dropdown-group';
                heading.textContent = group.label;
                this.list.append(heading);
            }
            lastGroup = group;
            const item = document.createElement('button');
            item.type = 'button';
            item.tabIndex = -1;
            item.id = `${this.uid}-option-${this.entries.length}`;
            item.className = 'dropdown-option';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(this.isSelect ? option.selected : this.element.value === option.value));
            item.disabled = option.disabled || Boolean(group?.disabled);
            item.textContent = this.isSelect ? option.label.trim() : option.value;
            if (!this.isSelect && option.label !== option.value) {
                const description = document.createElement('span');
                description.className = 'block text-xs text-gray-500 dark:text-gray-400';
                description.textContent = option.label;
                item.append(description);
            }
            const index = this.entries.length;
            item.addEventListener('pointerdown', (event) => event.preventDefault());
            item.addEventListener('pointermove', () => this.setActive(index, false));
            item.addEventListener('click', (event) => {
                event.stopPropagation();
                if (item.disabled || this.disabled) return;
                const options = this.isSelect && this.element.multiple ? [...this.element.selectedOptions].filter((current) => !placeholder(current) && current !== option) : [];
                if (!this.isSelect || !this.element.multiple || !option.selected) options.push(option);
                this.commit(options);
                if (!this.element.multiple) { this.close(); this.trigger.focus(); }
            });
            this.entries.push({ option, item });
            this.list.append(item);
        }
        if (!this.entries.length) {
            const empty = document.createElement('div');
            empty.className = 'dropdown-empty';
            empty.textContent = 'Співпадінь не знайдено';
            this.list.append(empty);
        }
        this.setActive(this.entries.findIndex(({ option, item }) => !item.disabled && (option === previous || (this.isSelect && option.selected))), false);
    }

    setActive(index, scroll = true) {
        this.activeIndex = index;
        this.entries.forEach(({ item }, current) => { item.dataset.active = String(current === index); });
        const item = this.entries[index]?.item;
        for (const field of [this.trigger, this.search].filter(Boolean)) attributes(field, { 'aria-activedescendant': item?.id ?? null });
        if (item && scroll) item.scrollIntoView({ block: 'nearest' });
    }

    move(step, edge) {
        const enabled = this.entries.map(({ item }, index) => item.disabled ? -1 : index).filter((index) => index >= 0);
        let index = enabled.indexOf(this.activeIndex);
        index = edge === 'Home' ? 0 : edge === 'End' ? enabled.length - 1 : index < 0 ? (step > 0 ? 0 : enabled.length - 1) : Math.max(0, Math.min(enabled.length - 1, index + step));
        if (enabled.length) this.setActive(enabled[index]);
    }

    open(focusSearch = false) {
        if (this.disabled || this.menu) return;
        active?.close();
        active = this;
        const menu = this.menu = document.createElement('div');
        menu.className = 'form-dropdown-menu dropdown-portal';
        menu.setAttribute('wire:ignore', '');
        menu.style.pointerEvents = 'auto';
        this.popover = typeof menu.showPopover === 'function';
        // Text fields open on focus; manual mode avoids light-dismiss from that same click.
        if (this.popover) menu.setAttribute('popover', this.isSelect ? 'auto' : 'manual');
        if (this.isSelect && (this.element.dataset.dropdownSearchable === 'true' || (this.source.options.length >= 8 && this.element.dataset.dropdownSearchable !== 'false'))) {
            this.search = document.createElement('input');
            this.search.type = 'search';
            this.search.className = 'dropdown-search';
            this.search.placeholder = 'Пошук...';
            attributes(this.search, { 'aria-label': 'Пошук у списку', role: 'combobox', 'aria-expanded': 'true', 'aria-autocomplete': 'list', 'aria-controls': `${this.uid}-list` });
            this.search.addEventListener('input', () => this.render());
            this.search.addEventListener('keydown', (event) => this.keydown(event));
            menu.append(this.search);
        }
        this.list = document.createElement('div');
        attributes(this.list, { id: `${this.uid}-list`, role: 'listbox', 'aria-labelledby': this.trigger.getAttribute('aria-labelledby') || this.uid, 'aria-multiselectable': this.element.multiple ? 'true' : null });
        menu.append(this.list);
        // Popovers enter the top layer without leaving an Alpine modal's DOM/focus scope.
        let owner = this.control;
        if (!this.popover) owner = this.element.closest('[x-dialog\\:panel], [role="dialog"], [x-trap], [x-trap\\.inert\\.noscroll]') || document.body;
        owner.append(menu);
        if (this.popover) {
            menu.showPopover();
            menu.addEventListener('toggle', (event) => { if (event.newState === 'closed' && this.menu === menu) this.close(); });
        } else menu.addEventListener('focusout', (event) => this.blur(event));
        attributes(this.trigger, { 'aria-controls': this.list.id, 'aria-expanded': 'true' });
        this.render();
        this.position();
        if (focusSearch && this.search) this.search.focus();
    }

    position() {
        if (!this.menu) return;
        if (!this.element.isConnected || !this.trigger.getClientRects().length) { this.close(); return; }
        const rect = this.trigger.getBoundingClientRect();
        const width = window.visualViewport?.width || innerWidth;
        const height = window.visualViewport?.height || innerHeight;
        const above = rect.top - 8;
        const below = height - rect.bottom - 8;
        const flip = below < 180 && above > below;
        Object.assign(this.menu.style, { maxHeight: `${Math.max(60, Math.min(320, flip ? above : below))}px`, width: `${Math.min(rect.width, width - 16)}px`, left: `${Math.max(8, Math.min(rect.left, width - rect.width - 8))}px` });
        this.menu.style.top = `${flip ? Math.max(8, rect.top - this.menu.offsetHeight - 4) : rect.bottom + 4}px`;
        if (!this.popover) {
            const actual = this.menu.getBoundingClientRect();
            this.menu.style.left = `${parseFloat(this.menu.style.left) + rect.left - actual.left}px`;
            this.menu.style.top = `${parseFloat(this.menu.style.top) + (flip ? rect.top - actual.height - 4 : rect.bottom + 4) - actual.top}px`;
        }
    }

    close() {
        const menu = this.menu;
        this.menu = this.search = null;
        if (this.popover && menu?.matches(':popover-open')) menu.hidePopover();
        menu?.remove();
        attributes(this.trigger, { 'aria-expanded': 'false', 'aria-controls': null, 'aria-activedescendant': null });
        if (active === this) active = null;
    }

    keydown(event) {
        if (this.disabled) { if (this.isSelect) event.preventDefault(); return; }
        if (event.isComposing || event.ctrlKey || event.metaKey) return;
        const key = event.key;
        if (key === 'Escape' && this.menu) { event.preventDefault(); event.stopPropagation(); this.close(); this.trigger.focus(); }
        else if (key === 'Tab') { if (event.target === this.search) this.trigger.focus(); this.close(); }
        else if (key === 'ArrowDown' || key === 'ArrowUp') {
            event.preventDefault();
            event.stopPropagation();
            const wasOpen = Boolean(this.menu);
            this.open();
            if (wasOpen || this.activeIndex < 0) this.move(key === 'ArrowDown' ? 1 : -1);
        } else if (this.menu && (key === 'Home' || key === 'End') && this.isSelect && event.target === this.trigger) {
            event.preventDefault();
            this.move(0, key);
        } else if (key === 'Enter' || (key === ' ' && this.isSelect && event.target === this.trigger)) {
            if (!this.menu) { if (this.isSelect) { event.preventDefault(); this.open(true); } }
            else if (this.entries[this.activeIndex]) { event.preventDefault(); event.stopPropagation(); this.entries[this.activeIndex].item.click(); }
            else this.close();
        } else if (!this.menu && this.isSelect && (key === 'Backspace' || key === 'Delete')) {
            event.preventDefault();
            this.commit([]);
        } else if (this.isSelect && event.target === this.trigger && key?.length === 1) {
            event.preventDefault();
            this.open();
            this.typeahead = (Date.now() - this.typedAt < 800 ? this.typeahead : '') + key.toLocaleLowerCase();
            this.typedAt = Date.now();
            const index = this.entries.findIndex(({ option, item }) => !item.disabled && option.label.trim().toLocaleLowerCase().startsWith(this.typeahead));
            if (index >= 0) this.setActive(index);
        }
    }

    blur(event) {
        if (!this.isSelect || this.control.contains(event.relatedTarget) || this.menu?.contains(event.relatedTarget)) return;
        this.element.dispatchEvent(new FocusEvent('blur', { relatedTarget: event.relatedTarget }));
        this.element.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: event.relatedTarget }));
    }

    invalid(event) {
        if (!this.isSelect) return;
        event.preventDefault();
        if (!this.validation) {
            this.validation = document.createElement('p');
            this.validation.className = 'dropdown-validation';
            attributes(this.validation, { id: `${this.uid}-validation`, role: 'alert' });
            this.control.append(this.validation);
        }
        this.validation.textContent = this.element.validationMessage;
        this.sync();
        const first = [...(this.element.form?.elements || [this.element])].find((element) => element.validity && !element.validity.valid);
        if (first === this.element) this.trigger.focus();
    }

    reset() {
        this.temporaryEmpty?.remove();
        this.temporaryEmpty = null;
        this.close();
        this.sync();
    }

    destroy() {
        this.destroyed = true;
        this.close();
        this.controller.abort();
        this.observer.disconnect();
        this.resize.disconnect();
        this.restorers.forEach((restore) => restore());
        const empty = this.element.value === '';
        this.temporaryEmpty?.remove();
        if (empty && this.isSelect && !this.element.multiple) this.element.selectedIndex = -1;
        this.element.classList.remove('dropdown-native');
        if (!this.hadFieldClass) this.element.classList.remove('dropdown-field');
        attributes(this.element, this.original);
        if (!this.isSelect) this.element.setAttribute('list', this.listId);
        if (this.parentPosition !== undefined) this.element.parentElement.style.position = this.parentPosition;
        this.control.remove();
        dropdowns.delete(this.element);
    }
}

export function enhanceDropdowns(root = document) {
    for (const dropdown of [...dropdowns.values()]) {
        if (!dropdown.element.isConnected || !dropdown.control.isConnected || dropdown.control.previousElementSibling !== dropdown.element) dropdown.destroy();
        else dropdown.sync();
    }
    for (const element of root.matches?.('select, input[list]') ? [root] : root.querySelectorAll('select, input[list]')) {
        if (!dropdowns.has(element) && !element.closest('.flatpickr-calendar, .datepicker-picker, .phpdebugbar') && (element.tagName === 'SELECT' || document.getElementById(element.getAttribute('list')))) dropdowns.set(element, new Dropdown(element));
    }
}

function queueEnhance() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; enhanceDropdowns(); });
}

export function initializeDropdowns() {
    if (initialized) return;
    initialized = true;
    const start = () => {
        enhanceDropdowns();
        new MutationObserver((records) => {
            const external = records.filter((record) => !record.target.closest?.('[data-dropdown-ui]'));
            if (!external.length) return;
            if (external.some((record) => record.type === 'childList' || record.target.matches?.('fieldset'))) queueEnhance();
            for (const dropdown of dropdowns.values()) dropdown.align();
            active?.position();
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'class', 'style', 'hidden'] });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
    document.addEventListener('pointerdown', (event) => { if (active && (!active.popover || !active.isSelect) && event.target !== active.trigger && !active.control.contains(event.target) && !active.menu?.contains(event.target)) active.close(); }, true);
    document.addEventListener('focusin', (event) => { if (active && event.target !== active.trigger && !active.control.contains(event.target) && !active.menu?.contains(event.target)) active.close(); });
    document.addEventListener('scroll', () => active?.position(), true);
    window.addEventListener('resize', () => active?.position());
    document.addEventListener('reset', (event) => setTimeout(() => {
        if (!event.defaultPrevented) for (const dropdown of dropdowns.values()) if (dropdown.element.form === event.target) dropdown.reset();
    }, 0), true);
    document.addEventListener('livewire:navigating', () => [...dropdowns.values()].forEach((dropdown) => dropdown.destroy()));
    document.addEventListener('livewire:navigated', queueEnhance);
    let hooked = false;
    const register = () => {
        if (hooked || !window.Livewire) return;
        hooked = true;
        const focus = new WeakMap();
        Livewire.hook('morph', ({ el }) => {
            const current = [...dropdowns.values()].find((dropdown) => el.contains(dropdown.element) && (dropdown.trigger === document.activeElement || dropdown.menu?.contains(document.activeElement)));
            if (current) focus.set(el, { element: current.element, id: current.element.id, search: current.search?.value, inSearch: document.activeElement === current.search });
            [...dropdowns.values()].filter((dropdown) => el === dropdown.element || el.contains(dropdown.element)).forEach((dropdown) => dropdown.destroy());
        });
        Livewire.hook('morphed', ({ el }) => {
            enhanceDropdowns(el);
            const saved = focus.get(el);
            const element = saved?.element.isConnected ? saved.element : saved?.id ? el.querySelector(`#${CSS.escape(saved.id)}`) : null;
            const dropdown = dropdowns.get(element);
            if (dropdown) {
                dropdown.trigger.focus();
                if (saved.inSearch && !dropdown.disabled) {
                    dropdown.open(true);
                    if (dropdown.search) { dropdown.search.value = saved.search || ''; dropdown.render(); }
                }
            }
            focus.delete(el);
        });
        Livewire.hook('morph.updated', queueEnhance);
    };
    document.addEventListener('livewire:init', register, { once: true });
    register();
}

document.addEventListener('alpine:initialized', initializeDropdowns);
