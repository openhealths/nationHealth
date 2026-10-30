const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/views/livewire/employee/parts/specialities.blade.php'), 'utf8');

function editor(rows) {
    const context = vm.createContext({ $wire: { entangle: () => 'SPECIALIST' } });
    context.window = context;
    vm.runInContext(source.match(/<script>([\s\S]*?)<\/script>/)[1], context);
    const definition = source.match(/x-data="([\s\S]*?)"\s+x-init=/)[1].replace(/@js\([^)]*\)/g, '{}');
    const component = vm.runInContext(`(${definition})`, context);
    component.specialities = structuredClone(rows);
    return component;
}

const primary = { speciality: 'PEDIATRICIAN', specialityOfficio: true, certificateNumber: 'PED' };
const additional = { speciality: 'OBSTETRICS_AND_GYNECOLOGY', specialityOfficio: false, certificateNumber: 'OBS' };

test('position and person groups preserve the complete ESOZ array and its original indexes', () => {
    const rows = [additional, primary, { speciality: 'NURSING', specialityOfficio: false }];
    const component = editor(rows);
    assert.deepEqual(Array.from(component.specialityEntries(true), entry => entry.speciality.speciality), ['PEDIATRICIAN']);
    assert.deepEqual(Array.from(component.specialityEntries(false), entry => entry.index), [0, 2]);
    assert.deepEqual(component.specialities, rows);
    assert.equal(component.additionalSpecialitiesOpen, false);
});

test('editing an additional qualification uses its original array index', () => {
    const component = editor([additional, primary, { speciality: 'NURSING', specialityOfficio: false }]);
    const entry = component.specialityEntries(false)[1];
    component.item = entry.index;
    component.newSpeciality = false;
    component.modalSpeciality = { ...entry.speciality, certificateNumber: 'UPDATED' };
    component.saveSpeciality();
    assert.equal(component.specialities[2].certificateNumber, 'UPDATED');
    assert.deepEqual(component.specialities[1], primary);
    assert.deepEqual(component.specialities[0], additional);
    assert.equal(component.specialities.length, 3);
    assert.equal(component.additionalSpecialitiesOpen, true);
});

test('adding a secondary qualification opens its group and keeps the existing primary', () => {
    const component = editor([primary]);
    component.openAddSpecialityModal();
    assert.equal(component.modalSpeciality.specialityOfficio, false);
    component.modalSpeciality.speciality = 'NURSING';
    component.saveSpeciality();
    assert.equal(component.additionalSpecialitiesOpen, true);
    assert.deepEqual(component.specialities[0], primary);
    assert.equal(component.specialityEntries(true).length, 1);
    assert.equal(component.specialityEntries(false).length, 1);
});

test('a person qualification is never promoted when the position has no primary', () => {
    const component = editor([additional]);
    assert.equal(component.specialityEntries(true).length, 0);
    assert.equal(component.specialityEntries(false).length, 1);
    assert.deepEqual(component.specialities, [additional]);
});

test('multiple primaries remain visible and cannot be silently replaced by another primary', () => {
    const component = editor([primary, { speciality: 'THERAPIST', specialityOfficio: true }]);
    assert.equal(component.specialityEntries(true).length, 2);
    component.newSpeciality = true;
    component.modalSpeciality = { speciality: 'NURSING', specialityOfficio: true };
    component.saveSpeciality();
    assert.equal(component.primaryConflict, true);
    assert.equal(component.specialities.length, 2);
});
