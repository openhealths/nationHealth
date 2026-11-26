const { test } = require('node:test');
const assert = require('node:assert/strict');
const { pathToFileURL } = require('node:url');
const path = require('node:path');

async function fixture(drawerState = null) {
    const { carePlanGroundsSearch } = await import(pathToFileURL(path.resolve('resources/js/care-plan-grounds-search.js')));
    const component = carePlanGroundsSearch(drawerState);
    component.watchers = new Map();
    component.$watch = (name, callback) => component.watchers.set(name, callback);
    component.$wire = {
        activityForm: { reason_code: 'I10' },
        set(field, value, live) {
            assert.equal(field, 'activityForm.reason_code');
            assert.equal(live, false);
            this.activityForm.reason_code = value;
            component.watchers.get('$wire.activityForm.reason_code')?.(value);
        },
    };
    component.$root = { dataset: { icd10SearchUrl: 'https://example.invalid/icd10' } };
    component.init();
    return component;
}

test('editing clears the saved diagnosis immediately and only delays the lookup', async (t) => {
    const component = await fixture();
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    t.mock.method(globalThis, 'fetch', async (url) => {
        requests.push(url.searchParams.get('search'));
        return { ok: true, json: async () => [{ code: 'E11.9', description: 'Diabetes' }] };
    });
    component.selectCode({ code: 'I10', description: 'Hypertension' });
    component.editQuery('Diabetes');
    assert.equal(component.$wire.activityForm.reason_code, '');
    assert.equal(component.selectedIcd10, null);
    assert.equal(component.query, 'Diabetes');
    t.mock.timers.tick(599);
    assert.deepEqual(requests, []);
    t.mock.timers.tick(1);
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(requests, ['Diabetes']);
    assert.equal(component.$wire.activityForm.reason_code, '');
    component.selectCode(component.results[0]);
    assert.equal(component.$wire.activityForm.reason_code, 'E11.9');
    assert.equal(component.query, 'E11.9 - Diabetes');
    component.destroy();
});

test('external draft changes replace the displayed code and cancel old results', async () => {
    const component = await fixture();
    component.selectCode({ code: 'I10', description: 'Hypertension' });
    component.$wire.activityForm = { id: 2, kind: 'service_request', reason_code: 'E11.9' };
    component.watchers.get('$wire.activityForm.id')();
    assert.equal(component.query, 'E11.9');
    assert.equal(component.selectedIcd10, null);
    assert.equal(component.showResults, false);
    component.$wire.activityForm = { id: null, kind: 'service_request', reason_code: '' };
    component.watchers.get('$wire.activityForm.reason_code')('');
    assert.equal(component.query, '');
});

for (const drawer of ['showServiceDrawer', 'showMedicationFormDrawer']) {
    test(`${drawer}: free input survives its own model clearing and a new form resets it`, async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        const fetch = t.mock.method(globalThis, 'fetch', async () => { throw new Error('Unexpected lookup'); });
        const component = await fixture(drawer);
        component.editQuery('Unselected diagnosis');
        assert.equal(component.query, 'Unselected diagnosis');
        assert.equal(component.$wire.activityForm.reason_code, '');
        component.watchers.get(`$wire.${drawer}`)(false);
        component.watchers.get(`$wire.${drawer}`)(true);
        assert.equal(component.query, '');
        t.mock.timers.tick(600);
        assert.equal(fetch.mock.callCount(), 0);
        component.destroy();
    });
}

test('selecting a diagnosis cancels pending lookup and prevents old results from reopening', async (t) => {
    const component = await fixture();
    let resolveResponse;
    let signal;
    t.mock.method(globalThis, 'fetch', (url, options) => {
        signal = options.signal;
        return new Promise(resolve => { resolveResponse = resolve; });
    });
    component.query = 'I10';
    const pending = component.search();
    component.selectCode({ code: 'E11.9', description: 'Diabetes' });
    assert.equal(signal.aborted, true);
    resolveResponse({ ok: true, json: async () => [{ code: 'I10', description: 'Old result' }] });
    await pending;
    assert.equal(component.$wire.activityForm.reason_code, 'E11.9');
    assert.equal(component.showResults, false);
    assert.deepEqual(component.results, []);
});

test('clearing or leaving the input cannot leave its previous diagnosis selected', async (t) => {
    const component = await fixture();
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const fetch = t.mock.method(globalThis, 'fetch', async () => { throw new Error('Unexpected lookup'); });
    component.editQuery('');
    t.mock.timers.tick(600);
    assert.equal(component.$wire.activityForm.reason_code, '');
    assert.equal(fetch.mock.callCount(), 0);
    component.editQuery('Diabetes');
    component.destroy();
    t.mock.timers.tick(600);
    assert.equal(fetch.mock.callCount(), 0);
});

test('HTTP failures are distinguished from a successful empty lookup', async (t) => {
    const component = await fixture();
    component.query = 'I10';
    const fetch = t.mock.method(globalThis, 'fetch', async () => ({ ok: false, status: 500 }));
    await component.search();
    assert.equal(component.searchFailed, true);
    assert.equal(component.showResults, false);
    component.cancelSearch();
    fetch.mock.mockImplementation(async () => ({ ok: true, json: async () => [] }));
    await component.search();
    assert.equal(component.searchFailed, false);
    assert.equal(component.showResults, true);
    assert.deepEqual(component.results, []);
});
