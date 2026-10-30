const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/views/components/select2.blade.php'), 'utf8');
const context = vm.createContext({ Map });
vm.runInContext(source.match(/<script>([\s\S]*?)<\/script>/)[1], context);

function selector(optionsSource = null, optionsWatch = null) {
    const watchers = new Map();
    const control = context.selectComponent('SPECIALITY_TYPE', 'modalSpeciality.speciality', optionsSource, optionsWatch);
    control.$wire = {
        dictionaries: { SPECIALITY_TYPE: { THERAPIST: 'Терапія', NURSING: 'Сестринська справа', RETIRED: 'Історична назва' } },
        $watch: (path, callback) => watchers.set(path, callback),
    };
    control.$watch = (path, callback) => watchers.set(path, callback);
    return { control, watchers };
}

test('a general speciality selector works without an employee form', () => {
    const { control, watchers } = selector();
    control.initializeOptions();
    assert.equal(control.options.length, 3);
    assert.equal(control.optionsMap.get('NURSING').label, 'Сестринська справа');
    assert.equal(watchers.size, 0);
});

test('an explicit employee restriction keeps an existing unavailable value on open', () => {
    const { control } = selector(() => ({ THERAPIST: 'Терапія' }), 'form.employeeType');
    control.selected = 'NURSING';
    control.initializeOptions();
    assert.equal(control.options.length, 1);
    assert.equal(control.selected, 'NURSING');
    assert.equal(control.search, 'Сестринська справа');
});

test('a missing historical label displays its code without erasing the value', () => {
    const { control } = selector(() => ({ THERAPIST: 'Терапія' }));
    control.selected = 'UNKNOWN_HISTORICAL_CODE';
    control.initializeOptions();
    assert.equal(control.selected, 'UNKNOWN_HISTORICAL_CODE');
    assert.equal(control.search, 'UNKNOWN_HISTORICAL_CODE');
});

test('a real role change clears an incompatible modal choice', () => {
    let choices = { THERAPIST: 'Терапія' };
    const { control, watchers } = selector(() => choices, 'form.employeeType');
    control.selected = 'THERAPIST';
    control.initializeOptions();
    choices = { NURSING: 'Сестринська справа' };
    watchers.get('form.employeeType')('ASSISTANT', 'SPECIALIST');
    assert.equal(control.selected, '');
    assert.equal(control.search, '');
    assert.equal(control.optionsMap.has('NURSING'), true);
});

test('selection and later model updates preserve dictionary codes', () => {
    const { control, watchers } = selector();
    control.watchSelected();
    control.initializeOptions();
    control.selectOption(control.optionsMap.get('THERAPIST'));
    assert.equal(control.selected, 'THERAPIST');
    assert.equal(control.search, 'Терапія');
    control.selected = 'RETIRED';
    watchers.get('selected')('RETIRED');
    assert.equal(control.search, 'Історична назва');
});
