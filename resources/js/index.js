import focus from '@alpinejs/focus';
import { referralIndex, referralDropdown } from './referral-index';
import mask from '@alpinejs/mask';
import ui from '@alpinejs/ui';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('referralIndex', referralIndex);
    window.Alpine.data('referralDropdown', referralDropdown);
    window.Alpine.plugin(mask);
    window.Alpine.plugin(focus);
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

        init() {
            this.$watch('searching', (isSearching) => {
                window.dispatchEvent(new CustomEvent(isSearching ? 'preloader:start' : 'preloader:stop'));
            });
        },

        destroy() {
            if (this.searching) {
                window.dispatchEvent(new CustomEvent('preloader:stop'));
            }
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
