const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');

const view = fs.readFileSync(path.join(__dirname, '../../resources/views/livewire/encounter-package/parts/referral.blade.php'), 'utf8');
const expression = view.match(/wire:key="encounter-electronic-referral-selector"\s+x-data="([\s\S]*?)"\s+x-init=/)[1];

function selector(selected = true) {
    const calls = [];
    const wire = {
        entangle: (name) => name === 'availableReferrals' ? [] : '',
        $set: (...args) => calls.push(['set', ...args]),
        async selectElectronicReferralByNumber(number) {
            calls.push(['number', number]);
            return selected;
        },
        async selectElectronicReferral(uuid) {
            calls.push(['uuid', uuid]);
            return selected;
        },
    };
    return { state: new Function('$wire', `return (${expression});`)(wire), calls, wire };
}

test('pasted number is normalized and selected without a dropdown click', async () => {
    const { state, calls } = selector();
    await state.updateNumber(' 0000111122223333\n', true);
    assert.deepEqual(calls.filter(([action]) => action === 'number'), [['number', '0000-1111-2222-3333']]);
    assert.equal(state.referralNumber, '0000-1111-2222-3333');
    assert.equal(state.selectedNumber, state.referralNumber);
    assert.equal(state.showReferrals, false);
    assert.equal(state.preparing, false);
});

test('partial typing filters locally; confirming searches once', async () => {
    const { state, calls } = selector();
    state.referrals = [{ id: 'a', requisition: '0000-1111-2222-3333' }, { id: 'b', requisition: '9999-1111-2222-3333' }];
    state.updateNumber('0000');
    assert.deepEqual(state.filteredReferrals.map(({ id }) => id), ['a']);
    assert.equal(calls.some(([action]) => action === 'number'), false);
    await state.resolveNumber();
    await state.resolveNumber();
    assert.equal(calls.filter(([action]) => action === 'number').length, 1);
});

test('ambiguous or failed search preserves the number and opens choices', async () => {
    const { state } = selector(false);
    await state.updateNumber('0000-1111-2222-3333', true);
    assert.equal(state.referralNumber, '0000-1111-2222-3333');
    assert.equal(state.selectedNumber, '');
    assert.equal(state.showReferrals, true);
});

test('dropdown selects the UUID while retaining the human number', async () => {
    const { state, calls } = selector();
    await state.selectReferral({ id: 'resource-uuid', requisition: '0000-1111-2222-3333' });
    assert.deepEqual(calls, [['uuid', 'resource-uuid']]);
    assert.equal(state.selectedNumber, '0000-1111-2222-3333');
    state.updateNumber('');
    assert.equal(state.selectedNumber, '');
    assert.equal(state.referralNumber, '');
});

test('a pending request blocks duplicate submissions and releases loading after a failure', async () => {
    const { state, wire } = selector();
    let reject;
    wire.selectElectronicReferralByNumber = () => new Promise((resolve, failure) => { reject = failure; });
    state.referralNumber = '0000-1111-2222-3333';
    const pending = state.resolveNumber();
    assert.equal(state.preparing, true);
    await state.resolveNumber();
    reject(new Error('connection lost'));
    await assert.rejects(pending, /connection lost/);
    assert.equal(state.preparing, false);
    assert.equal(state.referralNumber, '0000-1111-2222-3333');
});

test('an accepted asynchronous operation polls its result without submitting Use again', async () => {
    const { state, calls, wire } = selector('pending');
    wire.checkElectronicReferralPreparation = async () => true;
    await state.updateNumber('0000111122223333', true);
    state.destroy();
    assert.equal(state.pending, true);
    assert.equal(state.selectedNumber, '');
    await state.resolveNumber();
    assert.equal(calls.filter(([action]) => action === 'number').length, 1);
    await state.checkPreparation();
    assert.equal(state.pending, false);
    assert.equal(state.selectedNumber, '0000-1111-2222-3333');
});
