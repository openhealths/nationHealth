const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function prototype() {
    const source = fs.readFileSync('resources/views/livewire/encounter-package/parts/referral-prototype-state.blade.php', 'utf8');
    const script = source.split('<script>')[1].split('</script>')[0];
    const stored = new Map();
    let shouldFail = false;
    let nextId = 0;
    const location = { pathname: '/create', search: '', href: 'http://preview.test/create' };
    const entries = [{ state: null, url: location.href }];
    let cursor = 0;
    const selectEntry = entry => {
        const url = new URL(entry.url, location.href);
        location.href = url.href;
        location.pathname = url.pathname;
        location.search = url.search;
        history.state = entry.state;
    };
    const history = {
        state: null,
        pushState(state, _, url) { entries.splice(cursor + 1); entries.push({ state, url }); cursor++; selectEntry(entries[cursor]); },
        replaceState(state, _, url) { entries[cursor] = { state, url }; selectEntry(entries[cursor]); },
        back() { if (cursor) selectEntry(entries[--cursor]); },
        forward() { if (cursor + 1 < entries.length) selectEntry(entries[++cursor]); },
    };
    const restored = [];
    const context = {
        window: { scrollTo() {} },
        sessionStorage: {
            setItem(key, value) { if (shouldFail) throw new Error('QuotaExceededError'); stored.set(key, value); },
            getItem(key) { return stored.get(key) || null; },
            removeItem(key) { stored.delete(key); },
        },
        crypto: { randomUUID: () => 'preview-' + (++nextId) },
        location, history, URL,
    };
    vm.runInNewContext(script, context);
    const state = context.window.referralPrototype({
        key: 'test-preview', createUrl: '/create', reviewUrl: '/review',
        pieceUnit: 'piece', consultationLabel: 'Consultation',
        demoReferrals: [
            { service: { id: 'first', name: 'First', category: 'consultation' }, quantity: 1 },
            { service: { id: 'second', name: 'Second', category: 'consultation' }, quantity: 2 },
        ],
        messages: { quantityError: 'quantity', serviceRequired: 'service', storageError: 'storage' },
    });
    state.$nextTick = callback => callback();
    state.restoreForm = item => restored.push(JSON.parse(JSON.stringify(item)));
    return {
        state, stored, restored, history,
        reload() {
            const reloaded = context.window.referralPrototype(state.config);
            reloaded.$nextTick = state.$nextTick;
            reloaded.restoreForm = state.restoreForm;
            reloaded.init();
            return reloaded;
        },
        back() { history.back(); state.handleHistory(); },
        forward() { history.forward(); state.handleHistory(); },
        fail(value) { shouldFail = value; },
    };
}
const payload = () => ({ form: { quantity: 1, service_id: '' }, service: null });

test('a failed first save preserves the draft and retry creates distinct demo referrals', () => {
    const env = prototype();
    env.state.formDraft = { form: { note: 'unsaved note' } };
    const before = JSON.stringify(env.state.formDraft);
    env.fail(true);
    env.state.createPreview(payload());
    assert.equal(env.state.items.length, 0);
    assert.equal(env.state.editingId, null);
    assert.equal(JSON.stringify(env.state.formDraft), before);
    assert.equal(env.state.stage, 'form');
    assert.equal(env.state.errorMessage, 'storage');
    env.fail(false);
    env.state.createPreview(payload());
    const saved = JSON.parse(env.stored.get('test-preview'));
    assert.deepEqual(saved.items.map(item => item.service.id), ['first', 'second']);
    assert.equal(new Set(saved.items.map(item => item.id)).size, 2);
    assert.equal(env.state.stage, 'review');
});

test('failed edits and deletion leave the last saved preview intact', () => {
    const env = prototype();
    env.state.createPreview({ form: { quantity: 1, service_id: 'original' }, service: { id: 'original', name: 'Original' } });
    const id = env.state.editingId;
    const before = JSON.stringify(env.state.items);
    env.fail(true);
    env.state.createPreview({ form: { quantity: 2, service_id: 'changed' }, service: { id: 'changed', name: 'Changed' } });
    assert.equal(JSON.stringify(env.state.items), before);
    assert.equal(env.state.editingId, id);
    env.state.removeItem(id);
    assert.equal(JSON.stringify(env.state.items), before);
    assert.equal(JSON.parse(env.stored.get('test-preview')).items[0].service.id, 'original');
});

test('back restores the item from that history entry and saving changes that item only', () => {
    const env = prototype();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    const item = (id, quantity) => ({ form: { quantity, service_id: id, note: id }, service: { id, name: id } });
    env.state.createPreview(item('A', 1));
    const a = env.state.editingId;
    env.state.newItem();
    env.state.createPreview(item('B', 2));
    const b = env.state.editingId;
    env.state.editItem(a);
    env.state.createPreview(item('A', 3));
    env.state.editItem(b);
    env.back();
    env.back();
    assert.equal(env.state.editingId, a);
    const restored = env.restored.at(-1);
    assert.equal(restored.form.service_id, 'A');
    assert.equal(restored.form.quantity, 3);
    restored.form.quantity = 5;
    env.state.createPreview(restored);
    assert.equal(env.state.items.find(item => item.id === a).form.quantity, 5);
    assert.equal(env.state.items.find(item => item.id === b).form.quantity, 2);
});

test('deleting the edited item clears its saved draft and back does not restore it', () => {
    const env = prototype();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    env.state.createPreview({ form: { quantity: 1, service_id: 'A' }, service: { id: 'A', name: 'A' } });
    const a = env.state.editingId;
    env.state.editItem(a);
    env.state.saveForm({ form: { quantity: 3, service_id: 'A', note: 'saved draft' }, service: { id: 'A', name: 'A' } });
    env.back();
    env.state.removeItem(a);
    const stored = JSON.parse(env.stored.get('test-preview'));
    assert.equal(stored.items.length, 0);
    assert.equal(stored.formDraft, null);
    env.back();
    assert.equal(env.state.editingId, null);
    assert.equal(env.restored.at(-1).form.service_id, '');
});

test('back and forward restore the draft belonging to the new form', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    env.state.createPreview({ form: { quantity: 1, service_id: 'A' }, service: { id: 'A', name: 'A' } });
    const a = env.state.editingId;
    env.state.newItem();
    env.state.saveForm({ form: { quantity: 2, service_id: '', note: 'new saved draft' }, service: null });
    env.back();
    assert.equal(env.state.stage, 'review');
    assert.equal(env.state.editingId, a);
    env.forward();
    assert.equal(env.state.editingId, null);
    assert.equal(env.restored.at(-1).form.note, 'new saved draft');
    assert.equal(JSON.parse(env.stored.get('test-preview')).formDraft.form.note, 'new saved draft');
});

test('discard resets the notification method for the next referral', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    env.state.informMethod = 'none';
    env.state.saveForm(payload());
    env.state.discard();
    assert.equal(env.state.informMethod, 'sms');
    assert.equal(env.state.formDraft, null);
    assert.equal(env.stored.has('test-preview'), false);
});

test('editing an item again restores its saved form draft', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    env.state.createPreview({ form: { quantity: 1, service_id: 'A' }, service: { id: 'A', name: 'A' } });
    const id = env.state.editingId;
    env.state.editItem(id);
    env.state.saveForm({ form: { quantity: 2, service_id: 'A', note: 'saved edit' }, service: { id: 'A', name: 'A' } });
    env.back();
    env.state.editItem(id);
    assert.equal(env.restored.at(-1).form.note, 'saved edit');
});

test('returning from the empty-list error restores an empty new form', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    env.state.createPreview({ form: { quantity: 1, service_id: 'A' }, service: { id: 'A', name: 'A' } });
    env.state.removeItem(env.state.editingId);
    env.state.openSignature();
    env.state.returnToForm();
    assert.equal(env.state.stage, 'form');
    assert.equal(env.restored.at(-1).form.service_id, '');
    assert.equal(env.restored.at(-1).service, null);
});

test('drafts for different referrals survive switching between their forms', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    const item = (service, note) => ({ form: { quantity: 1, service_id: service, note }, service: { id: service, name: service } });
    env.state.createPreview(item('A', 'original A'));
    const a = env.state.editingId;
    env.state.newItem();
    env.state.createPreview(item('B', 'original B'));
    const b = env.state.editingId;
    env.state.editItem(a);
    env.state.saveForm(item('A', 'draft A'));
    env.back();
    env.state.editItem(b);
    env.state.saveForm(item('B', 'draft B'));
    env.state.editItem(a);
    assert.equal(env.restored.at(-1).form.note, 'draft A');
    env.state.editItem(b);
    assert.equal(env.restored.at(-1).form.note, 'draft B');
});

test('committing one referral replaces its draft and retains another referral draft', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    const item = (service, note) => ({ form: { quantity: 1, service_id: service, note }, service: { id: service, name: service } });
    env.state.createPreview(item('A', 'original A'));
    const a = env.state.editingId;
    env.state.newItem();
    env.state.createPreview(item('B', 'original B'));
    const b = env.state.editingId;
    env.state.editItem(a); env.state.saveForm(item('A', 'draft A'));
    env.state.editItem(b); env.state.saveForm(item('B', 'draft B'));
    env.state.editItem(a); env.state.createPreview(item('A', 'committed A'));
    env.state.editItem(a);
    assert.equal(env.restored.at(-1).form.note, 'committed A');
    env.state.editItem(b);
    assert.equal(env.restored.at(-1).form.note, 'draft B');
});


test('discarding a new draft clears only that slot and keeps previously edited referrals', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    const existing = { form: { quantity: 1, service_id: 'A', note: 'original' }, service: { id: 'A', name: 'A' } };
    env.state.createPreview(existing);
    const id = env.state.editingId;
    env.state.editItem(id);
    env.state.saveForm({ ...existing, form: { ...existing.form, note: 'saved edit' } });
    env.back();
    env.state.newItem();
    env.state.saveForm({ form: { quantity: 1, service_id: '', note: 'new note' }, service: null });
    env.back();
    env.state.newItem();
    assert.equal(env.restored.at(-1).form.note, 'new note');

    env.fail(true);
    assert.equal(env.state.discardFormDraft(), false);
    assert.equal(JSON.parse(env.stored.get('test-preview')).drafts.new.form.note, 'new note');
    env.fail(false);
    assert.equal(env.state.discardFormDraft(), true);
    assert.equal(env.restored.at(-1).form.note, '');
    const stored = JSON.parse(env.stored.get('test-preview'));
    assert.equal(stored.drafts.new, undefined);
    assert.equal(stored.formDraft, null);
    assert.equal(stored.items[0].form.note, 'original');

    env.state.newItem();
    assert.equal(env.restored.at(-1).form.note, '');
    env.state.editItem(id);
    assert.equal(env.restored.at(-1).form.note, 'saved edit');
});

test('discarding an edited draft stays empty after browser back and reload without removing the referral', () => {
    const env = prototype();
    env.state.init();
    const defaultForm = { quantity: 1, service_id: '', note: '' };
    env.state.formReady(defaultForm);
    const existing = { form: { quantity: 1, service_id: 'A', note: 'original' }, service: { id: 'A', name: 'A' } };
    env.state.createPreview(existing);
    const id = env.state.editingId;
    env.state.editItem(id);
    env.state.saveForm({ ...existing, form: { ...existing.form, quantity: 3, note: 'saved edit' } });
    const savedItems = JSON.parse(env.stored.get('test-preview')).items;

    env.fail(true);
    assert.equal(env.state.discardFormDraft(), false);
    assert.equal(env.history.state.editingId, id);
    assert.equal(env.state.editingId, id);
    assert.equal(JSON.parse(env.stored.get('test-preview')).drafts['ref:' + id].form.note, 'saved edit');

    env.fail(false);
    assert.equal(env.state.discardFormDraft(), true);
    env.history.pushState(null, '', '/encounter');
    env.back();
    assert.equal(env.state.stage, 'form');
    assert.equal(env.state.editingId, null);
    assert.equal(env.restored.at(-1).form.service_id, '');
    assert.equal(env.restored.at(-1).form.note, '');
    assert.equal(env.restored.at(-1).service, null);

    const reloaded = env.reload();
    let reloadedForm = { form: { ...defaultForm }, service: null };
    reloaded.restoreForm = item => { reloadedForm = JSON.parse(JSON.stringify(item)); };
    reloaded.formReady(defaultForm);
    assert.equal(reloaded.stage, 'form');
    assert.equal(reloaded.editingId, null);
    assert.equal(reloaded.formDraft, null);
    assert.equal(reloaded.drafts['ref:' + id], undefined);
    assert.deepEqual(JSON.parse(JSON.stringify(reloaded.items)), savedItems);
    assert.deepEqual(JSON.parse(env.stored.get('test-preview')).items, savedItems);
    assert.equal(reloadedForm.form.service_id, '');
    assert.equal(reloadedForm.form.note, '');
    assert.equal(reloadedForm.service, null);
});

test('editing without a selected service keeps the referral intact and demo services remain available for new items', () => {
    const env = prototype();
    env.state.init();
    env.state.formReady({ quantity: 1, service_id: '', note: '' });
    const existing = { form: { quantity: 4, service_id: 'original', note: 'original note' }, service: { id: 'original', name: 'Original' } };
    env.state.createPreview(existing);
    const id = env.state.editingId;
    env.state.editItem(id);
    const saved = env.stored.get('test-preview');
    const items = JSON.stringify(env.state.items);

    env.state.createPreview({ form: { quantity: 7, service_id: '', note: 'unfinished edit' }, service: null });
    assert.equal(env.state.errorMessage, 'service');
    assert.equal(env.state.showErrorModal, true);
    assert.equal(env.state.stage, 'form');
    assert.equal(env.state.editingId, id);
    assert.equal(JSON.stringify(env.state.items), items);
    assert.equal(env.stored.get('test-preview'), saved);

    env.state.newItem();
    env.state.createPreview(payload());
    assert.equal(env.state.stage, 'review');
    assert.equal(env.state.items.length, 2);
    assert.equal(env.state.items.find(item => item.id === id).form.service_id, 'original');
    assert.equal(env.state.items.find(item => item.id === id).form.quantity, 4);
    assert.equal(env.state.items.find(item => item.id !== id).service.id, 'first');
});

test('reloading a saved review keeps an existing referral separate from a new service draft', () => {
    const env = prototype();
    env.state.init();
    const defaultForm = { quantity: 1, service_id: '', note: '' };
    env.state.formReady(defaultForm);
    const item = (service, quantity, note) => ({ form: { quantity, service_id: service, note }, service: { id: service, name: service } });
    env.state.createPreview(item('service-A', 2, 'created A'));
    const a = env.state.editingId;
    env.state.newItem();
    env.state.saveForm(item('service-B', 5, 'draft B'));
    env.back();
    assert.equal(env.state.stage, 'review');
    assert.equal(env.state.editingId, a);
    assert.equal(env.state.persist(true), true);
    const saved = JSON.parse(env.stored.get('test-preview'));
    assert.equal(saved.formDraftId, null);
    assert.equal(saved.formDraft.form.service_id, 'service-B');

    const reloaded = env.reload();
    reloaded.formReady(defaultForm);
    reloaded.editItem(a);
    const restoredA = env.restored.at(-1);
    assert.equal(restoredA.form.service_id, 'service-A');
    assert.equal(restoredA.service.id, 'service-A');
    assert.equal(restoredA.form.quantity, 2);
    assert.equal(restoredA.form.note, 'created A');
    reloaded.createPreview(restoredA);
    assert.equal(reloaded.items.length, 1);
    assert.equal(reloaded.items[0].id, a);
    assert.equal(reloaded.items[0].form.service_id, 'service-A');
    assert.equal(reloaded.items[0].form.quantity, 2);

    reloaded.newItem();
    const restoredB = env.restored.at(-1);
    assert.equal(restoredB.form.service_id, 'service-B');
    assert.equal(restoredB.service.id, 'service-B');
    assert.equal(restoredB.form.quantity, 5);
    assert.equal(restoredB.form.note, 'draft B');
    assert.equal(reloaded.items[0].form.service_id, 'service-A');
});

test('legacy storage without formDraftId restores the draft for its edited referral', () => {
    const env = prototype();
    env.state.init();
    const defaultForm = { quantity: 1, service_id: '', note: '' };
    env.state.formReady(defaultForm);
    const original = { form: { quantity: 2, service_id: 'service-A', note: 'created A' }, service: { id: 'service-A', name: 'A' } };
    env.state.createPreview(original);
    const a = env.state.editingId;
    env.state.editItem(a);
    env.state.saveForm({ ...original, form: { ...original.form, note: 'legacy edit A' } });
    const legacy = JSON.parse(env.stored.get('test-preview'));
    delete legacy.formDraftId;
    delete legacy.drafts;
    env.stored.set('test-preview', JSON.stringify(legacy));

    const reloaded = env.reload();
    reloaded.formReady(defaultForm);
    assert.equal(reloaded.formDraftId, a);
    assert.equal(env.restored.at(-1).form.service_id, 'service-A');
    assert.equal(env.restored.at(-1).form.note, 'legacy edit A');
    reloaded.newItem();
    assert.equal(env.restored.at(-1).form.service_id, '');
    assert.equal(env.restored.at(-1).form.note, '');
    assert.equal(env.restored.at(-1).service, null);
    reloaded.editItem(a);
    assert.equal(env.restored.at(-1).form.note, 'legacy edit A');
});
