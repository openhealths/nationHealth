const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

function template(name) {
    return readFileSync(resolve(__dirname, `../../resources/views/livewire/employee/parts/${name}.blade.php`), 'utf8');
}

function cellValues(name, context) {
    const table = template(name).split('</table>')[0];
    return [...table.matchAll(/<td\b[^>]*\bx-text="([^"]+)"/g)]
        .map(([, expression]) => vm.runInNewContext(expression, context));
}

test('speciality rows display the qualification type label and preserve unknown historical codes', () => {
    const context = {
        speciality: { qualificationType: 'AWARDING' },
        specDict: {}, levelDict: {}, specQualDict: { AWARDING: 'Присвоєння' },
    };
    assert.ok(cellValues('specialities', context).includes('Присвоєння'));
    context.speciality.qualificationType = 'LEGACY_TYPE';
    assert.ok(cellValues('specialities', context).includes('LEGACY_TYPE'));
});

test('qualification rows expose additional information without an edit action', () => {
    const context = {
        qualification: { additionalInfo: 'Перша частина\nДруга частина' },
        qualTypeDict: {}, qualSpecDict: {},
    };
    assert.ok(cellValues('qualifications', context).includes('Перша частина\nДруга частина'));
    context.qualification.additionalInfo = null;
    assert.ok(cellValues('qualifications', context).includes('—'));
});

test('document modal can save without an issue date while still requiring type and number', () => {
    const source = template('documents');
    const expression = source.match(/:disabled="([^"]*modalDocument[^\"]*)"/)[1];
    const disabled = modalDocument => vm.runInNewContext(expression, { modalDocument });
    assert.equal(disabled({ type: 'NATIONAL_ID', number: '123456789', issuedAt: '' }), false);
    assert.equal(disabled({ type: 'NATIONAL_ID', number: '123456789', issuedAt: '01.01.2020' }), false);
    assert.equal(disabled({ type: '', number: '123456789' }), true);
    assert.equal(disabled({ type: 'NATIONAL_ID', number: '' }), true);
});
