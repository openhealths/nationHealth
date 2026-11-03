const assert = require('node:assert/strict');
const { test } = require('node:test');

test('Ukrainian patient search covers identity and subject.display without modifying source results', async () => {
    const { matchesReferral } = await import('../../resources/js/referral-index.js');
    const rows = [
        { id: 'identity', identity: { last_name: 'Шевченко', first_name: 'Тарас' } },
        { id: 'subject', subject: { display: 'Шевченко Т. Г.' } },
        { id: 'other', identity: { last_name: 'Петренко' } },
    ];
    assert.deepEqual(rows.filter(row => matchesReferral(row, '  ШЕВЧЕНКО  ')).map(row => row.id), ['identity', 'subject']);
    assert.equal(rows.length, 3);
});

test('status aliases combine and clearing statuses restores all results', async () => {
    const { matchesReferral } = await import('../../resources/js/referral-index.js');
    const rows = ['entered-in-error', 'entered_in_error', 'recalled', 'revoked', 'active'].map(status => ({ status }));
    assert.equal(rows.filter(row => matchesReferral(row, '', ['entered_in_error', 'revoked'])).length, 4);
    assert.equal(rows.filter(row => matchesReferral(row, '', [])).length, 5);
});

test('in_progress uses status or active/new processing state, excluding queues, drafts and terminal records', async () => {
    const { matchesReferral } = await import('../../resources/js/referral-index.js');
    const rows = [
        ['working', 'in_progress', ''],
        ['working_alias', 'in-progress', ''],
        ['program', 'active', 'in_progress'],
        ['new_program', 'new', 'in-progress'],
        ['queued', 'in_queue', ''],
        ['active_queue', 'active', 'in_queue'],
        ['new_queue', 'new', 'in_queue'],
        ...['completed', 'revoked', 'recalled', 'entered_in_error', 'entered-in-error', 'draft']
            .map(status => [status, status, 'in_progress']),
    ].map(([id, status, program_processing_status]) => ({ id, status, program_processing_status }));
    assert.deepEqual(rows.filter(row => matchesReferral(row, '', ['in_progress'])).map(row => row.id),
        ['working', 'working_alias', 'program', 'new_program']);
});
