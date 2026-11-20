export function carePlanGroundsSearch(drawerState = null) {
    return {
        query: '',
        boundCode: '',
        selectedIcd10: null,
        results: [],
        showResults: false,
        searching: false,
        searchFailed: false,
        searchRequest: null,
        searchTimer: null,

        init() {
            this.syncFromForm();
            this.$watch('$wire.activityForm.reason_code', (code) => {
                if ((code || '') !== this.boundCode) this.syncFromForm();
            });
            this.$watch('$wire.activityForm.id', () => this.syncFromForm());
            this.$watch('$wire.activityForm.kind', () => this.syncFromForm());
            if (drawerState) {
                this.$watch(`$wire.${drawerState}`, (open) => {
                    if (open) this.syncFromForm();
                });
            }
        },

        syncFromForm() {
            this.cancelSearch();
            this.boundCode = this.$wire.activityForm.reason_code || '';
            this.query = this.boundCode;
            this.selectedIcd10 = null;
        },

        cancelSearch() {
            clearTimeout(this.searchTimer);
            this.searchRequest?.abort();
            this.searchRequest = null;
            this.searching = false;
            this.searchFailed = false;
            this.results = [];
            this.showResults = false;
        },

        editQuery(value) {
            this.cancelSearch();
            this.query = value;
            this.selectedIcd10 = null;
            this.boundCode = '';
            this.$wire.set('activityForm.reason_code', '', false);
            this.searchTimer = setTimeout(() => this.search(), 600);
        },

        selectCode(result) {
            this.cancelSearch();
            this.selectedIcd10 = result;
            this.query = `${result.code} - ${result.description}`;
            this.boundCode = result.code;
            this.$wire.set('activityForm.reason_code', result.code, false);
        },

        async search() {
            this.searchFailed = false;
            this.showResults = false;
            const value = this.query.trim();
            if (value.length < (/^[a-zA-Z0-9.]+$/.test(value) ? 1 : 3)) return;
            const url = new URL(this.$root.dataset.icd10SearchUrl);
            url.searchParams.set('search', value);
            const request = new AbortController();
            this.searchRequest = request;
            this.searching = true;
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });
                if (!response.ok) throw new Error('Diagnosis lookup failed');
                const results = await response.json();
                if (this.searchRequest === request) {
                    this.results = results;
                    this.showResults = true;
                }
            } catch (error) {
                if (error.name !== 'AbortError' && this.searchRequest === request) {
                    this.results = [];
                    this.showResults = false;
                    this.searchFailed = true;
                }
            } finally {
                if (this.searchRequest === request) this.searching = false;
            }
        },

        destroy() {
            this.cancelSearch();
        },
    };
}
