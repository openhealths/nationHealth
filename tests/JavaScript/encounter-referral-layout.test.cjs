const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

// The PHP layout tests export the real Blade form. No patient API or signing is used.
let browser, fixture, css;
const fixtureAssets = new Map();
before(async () => {
    execFileSync(process.env.PHP_BINARY || 'php', ['vendor/bin/phpunit', 'tests/Feature/Encounter/EncounterReferralLayoutTest.php', '--filter', 'test_form_has_mockup_sections_and_program_info_is_hidden_initially'], {
        env: { ...process.env, REFERRAL_UI_EXPORT: '1' }, timeout: 120000,
    });
    fixture = JSON.parse(fs.readFileSync('storage/cli-testing/referral-layout-fixture.json', 'utf8'));
    const build = fs.existsSync('storage/cli-testing/referral-layout-build/manifest.json') ? 'storage/cli-testing/referral-layout-build/' : 'public/build/';
    const manifest = JSON.parse(fs.readFileSync(build + 'manifest.json', 'utf8'));
    for (const root of new Set([build, 'public/build/'])) {
        if (!fs.existsSync(root + 'manifest.json')) continue;
        const entries = Object.values(JSON.parse(fs.readFileSync(root + 'manifest.json', 'utf8')));
        for (const entry of entries) {
            for (const file of [entry.file, ...(entry.assets || []), ...(entry.css || [])]) {
                const pathname = new URL('/build/' + file, 'http://referral-ui.test/').pathname;
                if (!fixtureAssets.has(pathname)) fixtureAssets.set(pathname, root + file);
            }
        }
    }
    css = fs.readFileSync(build + manifest['resources/css/app.css'].file, 'utf8').replace(/@import\s*(?:"[^"]*"|'[^']*'|url\([^)]*\))[^;]*;/g, '');
    browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
});
after(async () => { await browser?.close(); });

async function mount(t, dark = false, width = 1440, selected = false, realLivewire = false, probeInit = false, allowUnexpectedRequests = false) {
    const activeFixture = selected === 'prototype' ? { ...fixture, ...fixture.prototype } : selected ? { ...fixture, ...fixture.selected } : fixture;
    const page = await browser.newPage({ viewport: { width, height: 1000 } });
    const unexpectedRequests = [];
    t.after(async () => {
        await page.close();
        if (!allowUnexpectedRequests) assert.deepEqual(unexpectedRequests, [], 'No request outside the fixture may reach the network');
    });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const denyUnexpected = route => {
        unexpectedRequests.push({ method: route.request().method(), url: route.request().url() });
        return route.abort();
    };
    await page.route('**/*', denyUnexpected);
    await page.route('http://referral-ui.test/**', route => {
        if (route.request().method() !== 'GET') return denyUnexpected(route);
        const { pathname } = new URL(route.request().url());
        if (pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<html></html>' });
        const file = fixtureAssets.get(pathname);
        return file ? route.fulfill({ path: file }) : denyUnexpected(route);
    });
    await page.goto('http://referral-ui.test/');
    await page.setContent(`<!doctype html><html class="${dark ? 'dark' : ''}"><head><base href="http://referral-ui.test/"></head><body class="bg-white dark:bg-gray-800">${fixture.headerHtml}<main class="responsive-content p-4 lg:ml-78 pt-8">${activeFixture.html}</main></body></html>`);
    assert.equal(await page.evaluate(() => document.compatMode), 'CSS1Compat');
    await page.evaluate(({ catalogHtml, drawerHeaders }) => {
        // Hydrate nested Livewire placeholders with their actual initial Blade render.
        let headerIndex = 0;
        for (const template of document.querySelectorAll('template[x-teleport]')) {
            const slot = template.content.querySelector('[data-dialog-header]');
            if (slot) slot.innerHTML = drawerHeaders[headerIndex++];
        }
        // Render the real reusable catalog inside its lazy Livewire slot.
        const template = [...document.querySelectorAll('template[x-teleport]')]
            .find(template => template.content.querySelector('[data-referral-service-catalog]'));
        template.content.querySelector('[data-referral-service-catalog]').innerHTML = catalogHtml;
        for (const element of template.content.querySelectorAll('[wire\\:dirty], [wire\\:loading], [wire\\:loading\\.flex]')) {
            element.style.display = 'none';
        }
    }, activeFixture);
    await page.addStyleTag({ content: css });
    if (realLivewire) {
        const blockedRequests = [];
        await page.route('**/livewire/update', route => {
            blockedRequests.push(route.request().postData());
            return route.abort();
        });
        await page.evaluate(({ prototype, probeInit }) => {
            const root = document.querySelector('[data-referral-prototype-form]').closest('[wire\\:id]');
            if (probeInit) root.setAttribute('wire:init', 'loadMoreReferralEpisodeOptions');
            root.setAttribute('wire:snapshot', JSON.stringify(prototype.snapshot));
            root.setAttribute('wire:effects', JSON.stringify(prototype.effects));
            window.referralLivewireRootId = root.getAttribute('wire:id');
            for (const element of document.querySelectorAll('[wire\\:id]')) {
                if (element === root) continue;
                // Static header/catalog fixtures have no server in this browser test.
                element.setAttribute('data-static-livewire-fixture', '');
                element.removeAttribute('wire:id');
                element.removeAttribute('wire:snapshot');
                element.removeAttribute('wire:effects');
            }
            for (const element of document.querySelectorAll('[data-static-livewire-fixture], [data-static-livewire-fixture] *')) {
                for (const attribute of [...element.attributes]) {
                    if (attribute.name.startsWith('wire:poll') || attribute.name === 'wire:init') element.removeAttribute(attribute.name);
                }
            }
            window.livewireScriptConfig = {
                csrf: 'synthetic-csrf', uri: 'http://referral-ui.test/livewire/update',
                moduleUrl: 'http://referral-ui.test/livewire/module', progressBar: 'data-no-progress-bar',
            };
        }, { prototype: activeFixture, probeInit });
        await page.addScriptTag({ path: 'node_modules/@alpinejs/ui/dist/cdn.js' });
        await page.addScriptTag({ path: 'vendor/livewire/livewire/dist/livewire.js' });
        await page.evaluate(() => {
            Livewire.start();
            window.referralTestState = Livewire.find(window.referralLivewireRootId);
        });
        await page.waitForFunction(() => !!window.referralTestState);
        return { page, errors, blockedRequests, unexpectedRequests };
    }
    await page.evaluate(fixture => {
        document.addEventListener('alpine:init', () => {
            const state = Alpine.reactive({
                encounterReferralForm: fixture.form,
                encounterReferralPrograms: fixture.programs,
                encounterReferralReferenceOptions: fixture.records, showSignatureModal: false, form: {}, referenceCalls: [], referenceSearchCalls: [],
                searchReferralReferenceOptions(query, type, episode) { this.referenceSearchCalls.push({ query, type, episode }); },
                addReferralReference(field, type, uuid) {
                    this.referenceCalls.push({ field, type, uuid });
                    const record = Object.values(this.encounterReferralReferenceOptions).find(record => record.type === type && record.uuid === uuid);
                    if (!this.encounterReferralForm[field].some(existing => existing.uuid === uuid && existing.type === type)) {
                        this.encounterReferralForm[field].push(record);
                    }
                },
                entangle(name) { return this[name] ?? false; },
                set(path, value) {
                    const parts = path.split('.');
                    let target = this;
                    for (const part of parts.slice(0, -1)) target = target[part];
                    target[parts.at(-1)] = value;
                    for (const control of document.querySelectorAll('input, select, textarea')) {
                        const binding = [...control.attributes].find(attribute => attribute.name.startsWith('wire:model'));
                        if (binding?.value === path && !Array.isArray(value) && value !== null && typeof value !== 'object') control.value = value;
                    }
                },
            });
            window.referralTestState = state;
            const catalogState = Alpine.reactive({
                filterCodeOrName: '', filterCategory: '', filterServiceIsActive: '',
                filterGroupIsActive: '', filterRequestAllowed: '',
                set: state.set,
            });
            Alpine.magic('wire', element => element.closest('[data-referral-service-catalog]') ? catalogState : state);
            Alpine.nextTick(() => {
                for (const input of document.querySelectorAll('input, select, textarea')) {
                    const attribute = [...input.attributes].find(attribute => attribute.name.startsWith('wire:model'));
                    if (!attribute) continue;
                    const inputState = input.closest('[data-referral-service-catalog]') ? catalogState : state;
                    const getValue = () => attribute.value.split('.').reduce((value, key) => value?.[key], inputState);
                    input.value = getValue() ?? '';
                    input.addEventListener('change', () => inputState.set(attribute.value, input.value));
                }
            });
        }, { once: true });
    }, activeFixture);
    await page.addScriptTag({ path: 'node_modules/@alpinejs/focus/dist/cdn.js' });
    await page.addScriptTag({ path: 'node_modules/@alpinejs/ui/dist/cdn.js' });
    await page.addScriptTag({ path: 'node_modules/alpinejs/dist/cdn.js' });
    await page.waitForFunction(() => window.referralTestState !== undefined);
    return { page, errors, unexpectedRequests };
}

async function openProgramMenu(page) {
    const menu = page.locator('#encounterReferralProgramOptions');
    if (!await menu.isVisible()) await page.locator('#encounterReferralProgram').click();
    await menu.waitFor({ state: 'visible' });
    await page.waitForFunction(() => {
        const menu = document.querySelector('#encounterReferralProgramOptions');
        return getComputedStyle(menu.closest('[x-show]')).opacity === '1';
    });
    return menu;
}

async function chooseProgram(page, id) {
    const menu = await openProgramMenu(page);
    await menu.locator('[data-program-option="' + id + '"]').click();
    await menu.waitFor({ state: 'hidden' });
}

async function showProgramHelp(page, id) {
    const menu = await openProgramMenu(page);
    await menu.locator('[data-program-help="' + id + '"]').click();
    await menu.waitFor({ state: 'hidden' });
}

test('the program menu opens without help and only its question icon shows program details', async t => {
    const { page, errors } = await mount(t);
    const help = page.locator('#encounterReferralProgramInfo');
    const programId = fixture.programs[0].id;
    assert.equal(await help.isVisible(), false);
    const menu = await openProgramMenu(page);
    assert.equal(await help.isVisible(), false);
    assert.equal(await menu.locator('[data-program-help]').count(), fixture.programs.length);
    await page.screenshot({ path: 'storage/cli-testing/referral-program-dropdown.png' });
    await chooseProgram(page, programId);
    assert.equal(await help.isVisible(), false, 'Selecting a program name must not show help');
    await showProgramHelp(page, programId);
    await help.waitFor({ state: 'visible' });
    assert.match(await help.textContent(), /Обов'язковість використання плану лікування для ЕН/);
    assert.match(await help.textContent(), /Ні/);
    await showProgramHelp(page, programId);
    await help.waitFor({ state: 'hidden' });
    assert.deepEqual(errors, []);
});

test('changing the program closes its help and questions do not change the selected program', async t => {
    const { page, errors } = await mount(t);
    const help = page.locator('#encounterReferralProgramInfo');
    await showProgramHelp(page, fixture.programs[0].id);
    await help.waitFor({ state: 'visible' });
    await chooseProgram(page, 'preview-service-program');
    await help.waitFor({ state: 'hidden' });
    await showProgramHelp(page, 'preview-service-program');
    await help.waitFor({ state: 'visible' });
    assert.match(await help.textContent(), /Тестова програма медичних гарантій/);
    assert.match(await help.textContent(), /Так/);
    await showProgramHelp(page, fixture.programs[0].id);
    await page.waitForFunction(expected => {
        const info = document.querySelector('#encounterReferralProgramInfo');
        return info.textContent.includes(expected);
    }, fixture.programs[0].name);
    assert.equal(await page.evaluate(() => window.referralTestState.encounterReferralForm.program_id), 'preview-service-program');
    assert.match(await help.textContent(), /Ні/);
    assert.deepEqual(errors, []);
});
test('period selection updates both dates while retaining the signing times', async t => {
    const { page, errors } = await mount(t);
    await page.locator('#encounterReferralPeriod').evaluate(input => input.dispatchEvent(new CustomEvent('date-range-change', {
        bubbles: true, detail: { from: '2026-12-13', to: '2026-12-15' },
    })));
    const form = await page.evaluate(() => ({ ...window.referralTestState.encounterReferralForm }));
    assert.equal(form.started_at, '13.12.2026');
    assert.equal(form.ended_at, '15.12.2026');
    assert.equal(form.start_time, fixture.form.start_time);
    assert.equal(form.end_time, fixture.form.end_time);
    assert.deepEqual(errors, []);
});

test('light, dark and mobile form layouts keep the four sections and footer inside the viewport', async t => {
    for (const [name, dark, width] of [['light', false, 1440], ['dark', true, 1440], ['mobile', false, 390]]) {
        const { page, errors } = await mount(t, dark, width);
        assert.equal(await page.locator('form.space-y-6 > fieldset').count(), 4);
        const styled = await page.locator('form.space-y-6 > fieldset').first().evaluate(fieldset => getComputedStyle(fieldset).borderTopStyle);
        assert.equal(styled, 'solid', 'The production stylesheet must be applied');
        await page.screenshot({ path: 'storage/cli-testing/referral-preview-' + name + '.png', fullPage: true });
        for (const id of ['encounterReferralSelectedService', 'encounterReferralQuantity', 'encounterReferralCategory', 'encounterReferralPriority', 'encounterReferralPeriodMode', 'encounterReferralPeriod', 'encounterReferralProgram', 'encounterReferralRequester', 'encounterReferralRequesterOrganization', 'encounterReferralNote', 'encounterReferralPatientInstruction']) {
            const field = await page.locator('#' + id).boundingBox();
            const label = await page.locator('label[for="' + id + '"]').boundingBox();
            const border = await page.locator('label[for="' + id + '"]').evaluate(label => getComputedStyle(label).borderTopWidth);
            assert.equal(border, '0px', name + ': label has an unexpected border: ' + id);
            assert.ok(field && field.width > 50, name + ': field is too narrow: ' + id);
            const padding = await page.locator('#' + id).evaluate(control => parseFloat(getComputedStyle(control).paddingTop) + parseFloat(getComputedStyle(control).borderTopWidth));
            assert.ok(label && label.y + label.height <= field.y + padding - 1, name + ': label overlaps field text: ' + id + ': ' + JSON.stringify({ label, field, padding }));
        }
        const catalogIcon = await page.getByRole('button', { name: 'Обрати з довідника' }).locator('svg').boundingBox();
        assert.ok(catalogIcon.width <= 24 && catalogIcon.height <= 24, name + ': catalog icon is oversized');
        assert.equal(await page.locator('#encounterReferralProgramInfo').isVisible(), false);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
        assert.equal(overflow, false, name + ' has horizontal overflow');
        await page.screenshot({ path: `storage/cli-testing/referral-layout-${name}.png`, fullPage: true });
        assert.deepEqual(errors, [], name);
    }
});

test('the book opens the reusable service catalog with all filters and expandable groups', async t => {
    const { page, errors } = await mount(t);
    await page.getByRole('button', { name: 'Обрати з довідника' }).click();
    const catalog = page.locator('[data-referral-service-catalog]');
    await catalog.waitFor({ state: 'visible' });
    assert.match(await catalog.textContent(), /Пошук послуги/);
    await catalog.getByRole('button', { name: 'Додаткові параметри пошуку', exact: true }).click();
    await catalog.locator('select').first().waitFor({ state: 'visible' });
    assert.equal(await catalog.locator('select:visible').count(), 3);
    const root = catalog.locator('tr').filter({ hasText: 'Медичні послуги' });
    await root.locator('button[aria-expanded]').click();
    const group = catalog.locator('tr').filter({ hasText: 'Діагностичні процедури' }).first();
    await group.locator('button[aria-expanded]').click();
    const service = catalog.locator('tr').filter({ hasText: 'Неврологічне обстеження' });
    await service.waitFor({ state: 'visible' });
    assert.match(await service.textContent(), /N0002/);
    assert.equal(await service.locator('button').count(), 1);
    await page.screenshot({ path: 'storage/cli-testing/referral-catalog.png', fullPage: true });
    await page.getByRole('button', { name: 'Скасувати', exact: true }).click();
    await catalog.waitFor({ state: 'hidden' });
    assert.deepEqual(errors, []);
});

test('each record drawer has its own types and episode filter and adds to the correct field', async t => {
    const { page, errors } = await mount(t);
    const episodeA = fixture.records['episode:d'].uuid;
    const episodeB = fixture.records['observation:b'].episode_uuid;
    for (const [index, field, allowedTypes, selectedType, selectedRecord] of [
        [2, 'reason_reference', ['', 'condition', 'observation'], 'condition', fixture.records['condition:a']],
        [3, 'supporting_info', ['', 'condition', 'episode', 'observation', 'diagnostic_report'], 'diagnostic_report', fixture.records['diagnostic_report:c']],
    ]) {
        const section = page.locator('form.space-y-6 > fieldset').nth(index);
        assert.equal(await section.locator('button.item-add').count(), 1);
        await section.locator(field === 'supporting_info' ? '[data-reference-add="supporting"]' : 'button.item-add').click();
        const picker = page.locator('[data-selection-scope="' + field + '"]');
        await picker.waitFor({ state: 'visible' });
        assert.deepEqual(await picker.locator('[data-record-type] option').evaluateAll(options => options.map(option => option.value)), allowedTypes);
        assert.equal(await picker.locator('[data-record-episode] option').count(), 3);
        assert.equal(await picker.locator('[data-record-row]:visible').count(), field === 'reason_reference' ? 2 : 4);
        const search = picker.getByRole('searchbox');
        await search.fill(selectedRecord.uuid);
        await page.waitForFunction(uuid => window.referralTestState.referenceSearchCalls.at(-1)?.query === uuid, selectedRecord.uuid);
        await picker.locator('[data-record-row="' + selectedType + ':' + selectedRecord.uuid + '"]').waitFor({ state: 'visible' });
        await search.fill('');
        await picker.locator('[data-record-type]').selectOption(selectedType);
        await picker.locator('[data-record-episode]').selectOption(episodeB);
        await picker.locator('[data-record-row="' + selectedType + ':' + selectedRecord.uuid + '"]').waitFor({ state: 'hidden' });
        assert.equal(await picker.locator('[data-record-row]:visible').count(), 0);
        await picker.locator('[data-record-episode]').selectOption(episodeA);
        await picker.locator('[data-record-row="' + selectedType + ':' + selectedRecord.uuid + '"]').waitFor({ state: 'visible', timeout: 5000 });
        await picker.locator('[data-record-row]:visible button:visible').click();
        await picker.locator('[data-record-row]:visible').getByText('Додано', { exact: true }).waitFor({ state: 'visible' });
        assert.deepEqual(await page.evaluate(() => window.referralTestState.referenceCalls.at(-1)), {
            field, type: selectedType, uuid: selectedRecord.uuid,
        });
        if (field === 'supporting_info') {
            await picker.locator('[data-record-type]').selectOption('episode');
            await picker.locator('[data-record-row="episode:' + episodeA + '"]').waitFor({ state: 'visible' });
            assert.match(await picker.locator('[data-record-row]:visible').textContent(), /Профілактика/);
        }
        await page.screenshot({ path: 'storage/cli-testing/referral-picker-' + field + '.png', fullPage: true });
        await page.getByRole('button', { name: 'Скасувати', exact: true }).click();
        await picker.waitFor({ state: 'hidden' });
    }
    assert.deepEqual(errors, []);
});
test('record and catalog drawers fit mobile screens in both themes', async t => {
    for (const dark of [false, true]) {
        const { page, errors } = await mount(t, dark, 390);
        await page.locator('form.space-y-6 > fieldset').nth(2).locator('button.item-add').click();
        const picker = page.locator('[data-selection-scope="reason_reference"]');
        await picker.waitFor({ state: 'visible' });
        await page.waitForFunction(() => {
            const field = document.querySelector('[data-selection-scope="reason_reference"] [data-record-episode]');
            const rect = field.getBoundingClientRect();
            return rect.x >= 0 && rect.right <= innerWidth;
        }, null, { timeout: 5000 });
        const episodeField = await picker.locator('[data-record-episode]').boundingBox();
        assert.ok(episodeField.x >= 0 && episodeField.x + episodeField.width <= 390);
        assert.equal(await picker.locator('[data-record-row]:visible').count(), 2);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        await page.screenshot({ path: 'storage/cli-testing/referral-picker-mobile-' + (dark ? 'dark' : 'light') + '.png' });
        await page.getByRole('button', { name: 'Скасувати', exact: true }).click();
        await picker.waitFor({ state: 'hidden' });
        await page.getByRole('button', { name: 'Обрати з довідника' }).click();
        const catalog = page.locator('[data-referral-service-catalog]');
        await catalog.waitFor({ state: 'visible' });
        await catalog.getByRole('button', { name: 'Додаткові параметри пошуку', exact: true }).click();
        await catalog.locator('select').first().waitFor({ state: 'visible' });
        assert.equal(await catalog.locator('select:visible').count(), 3);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        await page.screenshot({ path: 'storage/cli-testing/referral-catalog-mobile-' + (dark ? 'dark' : 'light') + '.png' });
        assert.deepEqual(errors, []);
    }
});
test('the preview program shows its settings and leaves medical submission disabled', async t => {
    const { page, errors } = await mount(t);
    await chooseProgram(page, 'preview-service-program');
    const help = page.locator('#encounterReferralProgramInfo');
    assert.equal(await help.isVisible(), false);
    await showProgramHelp(page, 'preview-service-program');
    await help.waitFor({ state: 'visible' });
    assert.match(await help.textContent(), /Тестова програма медичних гарантій/);
    assert.match(await help.textContent(), /Так/);
    assert.equal(await page.getByRole('button', { name: 'Створити направлення', exact: true }).isDisabled(), true);
    assert.equal(await page.getByRole('button', { name: 'Зберегти', exact: true }).isDisabled(), true);
    await chooseProgram(page, fixture.programs[0].id);
    await page.getByRole('button', { name: 'Створити направлення', exact: true }).waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('form button[type="submit"]').disabled);
    assert.deepEqual(errors, []);
});

test('all referral drawers reuse the header and keep theme, notifications and profile controls usable', async t => {
    if (!fixture.headerHtml.includes('data-app-header')) {
        return t.skip('Requires the shared application header and drawer layout from PR #1250.');
    }
    for (const width of [1440, 390, 2560]) {
        const { page, errors } = await mount(t, false, width);
        for (const trigger of [
            page.getByRole('button', { name: 'Обрати з довідника' }),
            page.locator('form.space-y-6 > fieldset').nth(2).locator('button.item-add'),
            page.locator('form.space-y-6 > fieldset').nth(3).locator('[data-reference-add="supporting"]'),
        ]) {
            await trigger.click();
            const header = page.locator('[data-dialog-header] [data-app-header]:visible');
            await header.waitFor({ state: 'visible' });
            const rect = await header.boundingBox();
            assert.equal(rect.y, 0);
            assert.equal(rect.width, width);
            const theme = header.getByRole('button', { name: 'Toggle theme color', exact: true });
            const wasDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
            await theme.click();
            assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark')), !wasDark);
            await header.getByRole('button', { name: 'Notifications', exact: true }).click();
            await header.locator('.notification-panel').waitFor({ state: 'visible' });
            await header.getByRole('button', { name: 'Notifications', exact: true }).click();
            await header.getByRole('button', { name: 'Open user menu', exact: true }).click();
            await header.locator('[id^="user-profile-menu-"]').waitFor({ state: 'visible' });
            await header.getByRole('button', { name: 'Open user menu', exact: true }).click();
            const drawer = header.locator('xpath=ancestor::*[@x-dialog][1]');
            await page.waitForFunction(() => {
                const nav = [...document.querySelectorAll('[data-dialog-header] [data-app-header]')]
                    .find(nav => nav.getBoundingClientRect().height > 0);
                const panel = nav.closest('[x-dialog]').querySelector('[x-dialog\\:panel]');
                return Math.abs(panel.getBoundingClientRect().left - panel.parentElement.getBoundingClientRect().left) < 0.05;
            });
            const panelTop = await drawer.locator('[x-dialog\\:panel]').evaluate(panel => panel.getBoundingClientRect().top);
            assert.ok(panelTop >= rect.height - 1.05 && panelTop <= rect.height);
            const panelLeft = await drawer.locator('[x-dialog\\:panel]').evaluate(panel => panel.getBoundingClientRect().left);
            const contentLeft = await page.locator('main').evaluate(main => main.getBoundingClientRect().left);
            assert.ok(panelLeft <= contentLeft + 1, 'The drawer must cover the whole form beside the sidebar on a wide screen');
            const surface = await drawer.locator('[data-dialog-surface]').evaluate(surface => {
                const rect = surface.getBoundingClientRect();
                const style = getComputedStyle(surface);
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = 1;
                const context = canvas.getContext('2d');
                context.fillStyle = style.backgroundColor;
                context.fillRect(0, 0, 1, 1);
                const alpha = context.getImageData(0, 0, 1, 1).data[3];
                return { top: rect.top, left: rect.left, bottom: rect.bottom, alpha, opacity: style.opacity, viewportHeight: innerHeight };
            });
            assert.equal(surface.top, 0, 'The opaque surface must cover the strip behind the header');
            assert.equal(surface.bottom, surface.viewportHeight);
            assert.ok(surface.left <= panelLeft + 1);
            assert.equal(surface.opacity, '1');
            assert.equal(surface.alpha, 255, 'The drawer surface must be opaque');
            // Fractional header sizes at browser zoom must not leave a transparent seam.
            await header.evaluate(nav => { nav.style.paddingTop = '10.25px'; });
            await page.waitForFunction(() => {
                const nav = [...document.querySelectorAll('[data-dialog-header] [data-app-header]')]
                    .find(nav => nav.getBoundingClientRect().height > 0);
                const panel = nav.closest('[x-dialog]').querySelector('[x-dialog\\:panel]');
                const bottom = nav.getBoundingClientRect().bottom;
                const top = panel.getBoundingClientRect().top;
                return top >= bottom - 1.05 && top <= bottom - 0.95;
            });
            const covered = await drawer.evaluate(drawer => {
                const header = drawer.querySelector('[data-app-header]').getBoundingClientRect();
                const panel = drawer.querySelector('[x-dialog\\:panel]').getBoundingClientRect();
                const element = document.elementFromPoint(panel.left + 8, header.bottom + 0.25);
                return element?.closest('[x-dialog]') === drawer;
            });
            assert.equal(covered, true, 'The form behind the drawer must not show below the header');
            await page.screenshot({ path: 'storage/cli-testing/referral-header-' + width + '.png' });
            await page.getByRole('button', { name: 'Скасувати', exact: true }).click();
            await header.waitFor({ state: 'hidden' });
            assert.equal(await page.locator('body > [data-app-header]').isVisible(), true);
        }
        assert.deepEqual(errors, []);
    }
});
test('selected references use dated tables with separate episodes and compact delete icons', async t => {
    for (const [name, dark, width] of [['light', false, 1440], ['dark', true, 1440], ['mobile', false, 390]]) {
        const { page, errors } = await mount(t, dark, width, true);
        const tables = page.locator('[data-referral-reference-table]');
        assert.equal(await tables.count(), 2);
        for (const [group, rows] of [['reason_reference:reason', 1], ['supporting_info:supporting', 3]]) {
            const table = page.locator('[data-referral-reference-table="' + group + '"]');
            assert.deepEqual(await table.locator('thead th').allTextContents().then(values => values.map(value => value.trim())), ['Дата', 'Назва', 'Дія']);
            assert.equal(await table.locator('tbody tr').count(), rows);
            assert.equal(await table.evaluate(table => getComputedStyle(table).borderBottomWidth), '4px');
            for (const icon of await table.locator('tbody button svg').all()) {
                const box = await icon.boundingBox();
                assert.ok(box.width <= 16 && box.height <= 16);
            }
        }
        assert.match(await tables.first().textContent(), /08.05.2026/);
        assert.match(await page.locator('[data-referral-reference-table="supporting_info:supporting"]').textContent(), /Епізод Профілактика/);
        assert.match(await page.locator('[data-referral-reference-table="supporting_info:supporting"]').textContent(), /Діагностичний звіт Комп/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        await page.screenshot({ path: 'storage/cli-testing/referral-selected-tables-' + name + '.png', fullPage: true });
        await page.locator('[data-reference-add="supporting"]').click();
        const picker = page.locator('[data-selection-scope="supporting_info"]');
        await picker.waitFor({ state: 'visible' });
        await picker.locator('[data-record-type]').selectOption('episode');
        await picker.locator('[data-record-row="episode:' + fixture.records['episode:d'].uuid + '"]').waitFor({ state: 'visible' });
        await page.waitForFunction(() => {
            const picker = document.querySelector('[data-selection-scope="supporting_info"]');
            return picker.querySelector('[data-record-type]').value === 'episode'
                && [...picker.querySelectorAll('[data-record-row]')].filter(row => row.getClientRects().length > 0).length === 1;
        });
        assert.equal(await picker.locator('[data-record-row]:visible').count(), 1);
        await page.getByRole('button', { name: 'Скасувати', exact: true }).click();
        assert.deepEqual(errors, []);
    }
});
test('the client prototype previews, edits and signs without sending a clinical request', async t => {
    const { page, errors } = await mount(t, false, 1440, 'prototype');
    const requests = [];
    page.on('request', request => {
        if (/livewire\/update|ehealth|keyContainer/i.test(request.url())) requests.push(request.url());
    });
    const form = page.locator('[data-referral-prototype-form]');
    const review = page.locator('[data-referral-prototype-review]');
    assert.equal(await form.locator('button.item-add').count(), 2);
    await chooseProgram(page, 'preview-service-program');
    assert.equal(await form.getByRole('button', { name: 'Створити направлення', exact: true }).isDisabled(), false);
    await page.evaluate(() => {
        const state = window.referralTestState;
        state.encounterReferralSelectedService = { id: 'service-one', code: 'T67002', name: 'Консультація Ендокринолога' };
        state.encounterReferralForm.service_id = 'service-one';
        state.set('encounterReferralForm.quantity', 2);
    });
    await page.locator('#encounterReferralQuantityUnit').selectOption('SESSION');
    await page.evaluate(() => { window.referralTestState.encounterReferralForm.quantity_unit = 'RAW_UNIT_VALUE'; });
    const payload = await page.locator('#encounterReferralQuantityUnit').evaluate(unit => Alpine.$data(unit).prototypePayload());
    assert.equal(payload.form.quantity_unit, 'RAW_UNIT_VALUE');
    assert.equal(payload.quantityUnitLabel, 'сеанс');
    await form.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    await review.waitFor({ state: 'visible' });
    assert.match(await review.textContent(), /2 сеанс/);
    assert.match(new URL(page.url()).pathname, /\/referrals\/preview$/);
    assert.equal(await review.locator('[data-preview-referral]').count(), 1);
    assert.match(await review.textContent(), /Консультація Ендокринолога/);
    await review.locator('[data-preview-referral]').first().getByRole('button', { name: 'Дії', exact: true }).click();
    await review.getByRole('button', { name: 'Редагувати', exact: true }).click();
    await form.waitFor({ state: 'visible' });
    assert.equal(await page.locator('#encounterReferralQuantityUnit').inputValue(), 'SESSION');
    assert.equal(await page.evaluate(() => window.referralTestState.encounterReferralForm.quantity_unit), 'RAW_UNIT_VALUE');
    await form.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    await review.waitFor({ state: 'visible' });
    await review.getByRole('button', { name: 'Додати направлення', exact: true }).click();
    await form.waitFor({ state: 'visible' });
    await page.evaluate(() => {
        const state = window.referralTestState;
        state.encounterReferralSelectedService = { id: 'service-two', code: 'T67003', name: 'Консультація Психіатра' };
        state.encounterReferralForm.service_id = 'service-two';
        state.set('encounterReferralForm.quantity', 3);
    });
    await form.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    await review.waitFor({ state: 'visible' });
    assert.equal(await review.locator('[data-preview-referral]').count(), 2);
    await page.screenshot({ path: 'storage/cli-testing/referral-prototype-review.png', fullPage: true });
    await review.locator('[data-preview-referral]').last().getByRole('button', { name: 'Дії', exact: true }).click();
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running'));
    await page.screenshot({ path: 'storage/cli-testing/referral-review-actions-light.png', fullPage: true });
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await page.screenshot({ path: 'storage/cli-testing/referral-review-actions-dark.png', fullPage: true });
    await page.evaluate(() => document.documentElement.classList.remove('dark'));
    await review.getByRole('button', { name: 'Редагувати', exact: true }).click();
    await form.waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('#encounterReferralQuantity').value === '3');
    await page.evaluate(() => window.referralTestState.set('encounterReferralForm.quantity', 4));
    await form.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    await review.waitFor({ state: 'visible' });
    assert.equal(await review.locator('[data-preview-referral]').count(), 2);
    await review.locator('[data-preview-referral]').last().getByRole('button', { name: 'Дії', exact: true }).click();
    await review.locator('[data-preview-referral]').last().getByRole('button', { name: 'Видалити', exact: true }).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-preview-referral]').length === 1);
    assert.match(await review.textContent(), /Консультація Ендокринолога/);
    await page.screenshot({ path: 'storage/cli-testing/referral-prototype-after-edit.png', fullPage: true });
    await review.getByRole('button', { name: 'Підписати направлення', exact: true }).click();
    const key = page.locator('#referralPreviewKeyFile');

    await page.locator('#referralPreviewKnedp').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running'));
    const keyBox = await page.locator('label[for="referralPreviewKeyFile"]').boundingBox();
    assert.ok(keyBox.x >= 0 && keyBox.x + keyBox.width <= 1440);
    assert.doesNotMatch(await page.locator('[x-dialog]:visible').textContent(), /forms\./);
    await page.screenshot({ path: 'storage/cli-testing/referral-prototype-signature.png' });
    const provider = page.locator('#referralPreviewKnedp');
    const password = page.locator('#referralPreviewPassword');
    const sign = page.getByRole('button', { name: 'Підписати', exact: true });
    const passwordError = page.locator('#referralPreviewPasswordError');
    await provider.selectOption('preview-knedp');
    await key.setInputFiles({ name: 'preview.dat', mimeType: 'application/octet-stream', buffer: Buffer.from('preview-only') });
    await sign.click();
    await passwordError.waitFor({ state: 'visible' });
    assert.match(await passwordError.textContent(), /Пароль/);
    assert.equal(await password.getAttribute('aria-invalid'), 'true');
    assert.equal(await provider.inputValue(), 'preview-knedp');
    assert.equal(await key.evaluate(input => input.files[0]?.name), 'preview.dat');
    assert.equal(await page.locator('#referralPreviewKnedpError').isVisible(), false);
    assert.equal(await page.locator('#referralPreviewKeyFileError').isVisible(), false);
    assert.equal(await page.locator('#referralPreviewError').isVisible(), false);
    await password.fill('preview-only-password');
    await passwordError.waitFor({ state: 'hidden' });
    await sign.click();
    await page.locator('#referralPreviewKnedp').waitFor({ state: 'hidden' });
    const stored = await page.evaluate(() => Object.values(sessionStorage).join(''));
    assert.doesNotMatch(stored, /preview-only-password|preview\.dat/);

    await page.screenshot({ path: 'storage/cli-testing/referral-prototype-after-edit.png', fullPage: true });
    await review.getByRole('button', { name: 'Підписати направлення', exact: true }).click();
    await provider.waitFor({ state: 'visible' });
    assert.equal(await provider.inputValue(), '');
    assert.equal(await key.evaluate(input => input.files.length), 0);
    assert.equal(await password.inputValue(), '');
    assert.equal(await passwordError.isVisible(), false);
    await sign.click();
    for (const field of ['Knedp', 'KeyFile', 'Password']) {
        await page.locator('#referralPreview' + field + 'Error').waitFor({ state: 'visible' });
    }
    await page.locator('[x-dialog]:visible').getByRole('button', { name: 'Скасувати', exact: true }).click();
    await provider.waitFor({ state: 'hidden' });
    assert.equal(await review.isVisible(), true);
    assert.deepEqual(requests, []);
    assert.deepEqual(errors, []);
});
test('the prototype error modal returns to creation and preserves entered values', async t => {
    const { page, errors } = await mount(t, true, 390, 'prototype');
    const form = page.locator('[data-referral-prototype-form]');
    await page.evaluate(() => {
        const state = window.referralTestState;
        state.encounterReferralSelectedService = { id: 'service-one', code: 'T67002', name: 'Консультація Ендокринолога' };
        state.encounterReferralForm.service_id = 'service-one';
        state.set('encounterReferralForm.quantity', 0);
    });
    await form.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    const modal = page.locator('#referralPreviewError');
    await modal.waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running'));
    assert.equal(await modal.locator('h2').textContent().then(value => value.trim()), 'Помилка');
    assert.match(await modal.textContent(), /Кількість має бути більшою за нуль/);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await page.screenshot({ path: 'storage/cli-testing/referral-prototype-error-mobile.png' });
    await modal.getByRole('button', { name: 'Повернутись до створення ЕН', exact: true }).click();
    await modal.waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#encounterReferralQuantity').inputValue(), '0');
    assert.deepEqual(errors, []);
});

test('the service popup covers adjacent fields and a local choice opens the referral review', async t => {
    for (const [width, dark] of [[1440, false], [1440, true], [390, true]]) {
        const { page, errors } = await mount(t, dark, width, 'prototype');
        const requests = [];
        page.on('request', request => { if (/livewire\/update|ehealth/i.test(request.url())) requests.push(request.url()); });
        await page.locator('#encounterReferralSelectedService').click();
        const popup = page.locator('#encounterReferralServiceOptions');
        await popup.waitFor({ state: 'visible' });
        const box = await popup.boundingBox();
        assert.ok(box.x >= 0 && box.x + box.width <= width);
        const input = page.locator('#encounterReferralServiceSearch');
        assert.equal(await input.evaluate(input => {
            const rect = input.getBoundingClientRect();
            return document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2) === input;
        }), true, 'The search input must be above the quantity field');
        await page.screenshot({ path: 'storage/cli-testing/referral-service-popup-' + width + '-' + (dark ? 'dark' : 'light') + '.png', fullPage: true });
        await input.fill('Психіатра');
        assert.equal(await popup.locator('button').filter({ hasText: 'Консультація Ендокринолога' }).count(), 0);
        await popup.getByRole('button', { name: /T67003.*Консультація Психіатра/ }).click();
        await popup.waitFor({ state: 'hidden' });
        assert.match(await page.locator('#encounterReferralSelectedService').textContent(), /T67003.*Консультація Психіатра/);
        await page.getByRole('button', { name: 'Створити направлення', exact: true }).click();
        const review = page.locator('[data-referral-prototype-review]');
        await review.waitFor({ state: 'visible' });
        assert.equal(await review.locator('[data-preview-referral]').count(), 1);
        assert.match(await review.textContent(), /Консультація Психіатра/);
        assert.match(new URL(page.url()).pathname, /\/referrals\/preview$/);
        assert.deepEqual(requests, []);
        assert.deepEqual(errors, []);
    }
});

test('an empty local form opens the mockup review with two marked example referrals', async t => {
    for (const [dark, width] of [[false, 1440], [true, 1440], [true, 390]]) {
        const { page, errors } = await mount(t, dark, width, 'prototype');
        await page.evaluate(() => {
            window.referralTestState.encounterReferralForm.service_id = '';
            window.referralTestState.encounterReferralSelectedService = null;
        });
        await page.getByRole('button', { name: 'Створити направлення', exact: true }).click();
        const review = page.locator('[data-referral-prototype-review]');
        await review.waitFor({ state: 'visible' });
        assert.equal(await review.locator('[data-preview-referral]').count(), 2);
        assert.match(await review.textContent(), /Тестові направлення/);
        assert.match(await review.textContent(), /Консультація Ендокринолога/);
        assert.match(await review.textContent(), /Консультація Психіатра/);
        assert.equal(await page.locator('#referralPreviewError').isVisible(), false);
        assert.match(new URL(page.url()).pathname, /\/referrals\/preview$/);
        await page.screenshot({ path: 'storage/cli-testing/referral-preview-examples-' + width + '-' + (dark ? 'dark' : 'light') + '.png', fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('body *')].filter(element => element.getBoundingClientRect().right > innerWidth + 1 && element.getBoundingClientRect().width > 0).slice(0, 8).map(element => ({ tag: element.tagName, classes: element.className, right: element.getBoundingClientRect().right, width: element.getBoundingClientRect().width })) )));
        assert.deepEqual(errors, []);
    }
});

test('changing preview notification clears the saved confirmation until it is saved again', async t => {
    const { page, errors } = await mount(t, false, 1440, 'prototype');
    await page.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    const review = page.locator('[data-referral-prototype-review]');
    await review.waitFor({ state: 'visible' });
    await review.getByRole('button', { name: 'Зберегти', exact: true }).click();
    const saved = review.locator('[x-show="prototypeSaved"]');
    await saved.waitFor({ state: 'visible' });
    await page.locator('#referralPreviewInformMethod').selectOption('none');
    await saved.waitFor({ state: 'hidden' });
    assert.equal(await page.evaluate(() => JSON.parse(sessionStorage.getItem(Alpine.$data(document.querySelector('[data-referral-prototype-review]')).config.key)).informMethod), 'sms');
    await review.getByRole('button', { name: 'Зберегти', exact: true }).click();
    await saved.waitFor({ state: 'visible' });
    assert.equal(await page.evaluate(() => JSON.parse(sessionStorage.getItem(Alpine.$data(document.querySelector('[data-referral-prototype-review]')).config.key)).informMethod), 'none');
    assert.deepEqual(errors, []);
});

test('dropping a key on the real signing drawer reaches the bound file input and bubbles its change', async t => {
    const { page, errors } = await mount(t);
    await page.evaluate(html => {
        const host = document.createElement('div');
        host.setAttribute('x-data', '{ showSignatureDrawer: true }');
    host.setAttribute('data-real-signature-host', '');
        host.innerHTML = html;
        document.body.append(host);
        Alpine.initTree(host);
        window.realKeyChanges = [];
        document.addEventListener('change', event => {
            if (event.target.id === 'realDropKeyFile') window.realKeyChanges.push(event.target.files[0]?.name);
        });
    }, fixture.signatureHtml);
    const target = page.locator('label[for="realDropKeyFile"]');
    await target.waitFor({ state: 'visible' });
    const dataTransfer = await page.evaluateHandle(() => {
        const transfer = new DataTransfer();
        transfer.items.add(new File(['synthetic container'], 'synthetic-key.dat', { type: 'application/octet-stream' }));
        return transfer;
    });
    await target.dispatchEvent('drop', { dataTransfer });
    await page.waitForFunction(() => document.querySelector('#realDropKeyFile').files[0]?.name === 'synthetic-key.dat');
    assert.equal(await page.locator('#realDropKeyFile').getAttribute('wire:model'), 'form.keyContainerUpload');
    assert.deepEqual(await page.evaluate(() => window.realKeyChanges), ['synthetic-key.dat']);
    const sign = page.locator('button[wire\\:click="sign"]');
    assert.equal(await sign.isDisabled(), true);
    assert.equal(await page.getByText('synthetic-key.dat', { exact: true }).count(), 0);
    await page.locator('#realDropKeyFile').dispatchEvent('livewire-upload-finish');
    await page.getByText('synthetic-key.dat', { exact: true }).waitFor({ state: 'visible' });
    assert.equal(await sign.isDisabled(), false);
    await page.evaluate(() => { window.referralTestState.form = { knedp: 'old-provider', password: 'old-password', keyContainerUpload: 'old-key' }; });
    await sign.locator('..').getByRole('button', { name: 'Скасувати', exact: true }).click();
    await page.waitForFunction(() => window.referralTestState.form.keyContainerUpload === null);
    assert.deepEqual(await page.evaluate(() => window.referralTestState.form), { knedp: '', password: '', keyContainerUpload: null });
    await page.evaluate(() => { Alpine.$data(document.querySelector('[data-real-signature-host]')).showSignatureDrawer = true; });
    await sign.waitFor({ state: 'visible' });
    assert.equal(await sign.isDisabled(), true);

    assert.deepEqual(errors, []);
});

test('the prototype never sends a component action with real Livewire initialized', async t => {
    const { page, errors, blockedRequests } = await mount(t, false, 1440, 'prototype', true);
    assert.equal(await page.evaluate(() => !!Livewire.find(window.referralLivewireRootId)), true);
    await page.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    const review = page.locator('[data-referral-prototype-review]');
    await review.waitFor({ state: 'visible' });
    await review.getByRole('button', { name: 'Зберегти', exact: true }).click();
    await review.getByRole('button', { name: 'Підписати направлення', exact: true }).click();
    await page.locator('#referralPreviewKnedp').waitFor({ state: 'visible' });
    await page.locator('#referralPreviewKnedp').selectOption('preview-knedp');
    await page.locator('#referralPreviewKeyFile').setInputFiles({ name: 'preview.dat', mimeType: 'application/octet-stream', buffer: Buffer.from('synthetic') });
    await page.locator('#referralPreviewPassword').fill('synthetic-password');
    await page.getByRole('button', { name: 'Підписати', exact: true }).click();
    await page.locator('#referralPreviewKnedp').waitFor({ state: 'hidden' });
    await page.waitForTimeout(150);
    assert.deepEqual(blockedRequests, []);
    assert.deepEqual(errors, []);
});

test('the real Livewire request guard detects a server click directive on the prototype', async t => {
    const { page, blockedRequests } = await mount(t, false, 1440, 'prototype', true);
    await page.evaluate(() => {
        const probe = document.createElement('button');
        probe.setAttribute('wire:click', 'loadMoreReferralEpisodeOptions');
        probe.setAttribute('data-request-probe', '');
        probe.textContent = 'Request guard probe';
        document.querySelector('[data-referral-prototype-form]').append(probe);
    });
    await page.locator('[data-request-probe]').click();
    await page.waitForFunction(() => window.Livewire !== undefined);
    for (let i = 0; i < 20 && !blockedRequests.length; i++) await page.waitForTimeout(25);
    assert.equal(blockedRequests.length, 1, 'A wire:click action must be detected and blocked before transmission');
    const component = JSON.parse(blockedRequests[0]).components[0];
    assert.equal(component.calls[0].method, 'loadMoreReferralEpisodeOptions');
    assert.equal(JSON.parse(component.snapshot).memo.id, await page.evaluate(() => window.referralLivewireRootId));
});

test('the request guard detects a prototype initialization action', async t => {
    const { page, blockedRequests } = await mount(t, false, 1440, 'prototype', true, true);
    for (let i = 0; i < 20 && !blockedRequests.length; i++) await page.waitForTimeout(25);
    assert.equal(blockedRequests.length, 1);
    const component = JSON.parse(blockedRequests[0]).components[0];
    assert.equal(component.calls[0].method, 'loadMoreReferralEpisodeOptions');
});

test('a selected program can be cleared from the menu', async t => {
    const { page, errors } = await mount(t);
    await chooseProgram(page, fixture.programs[0].id);
    await chooseProgram(page, '');
    assert.equal(await page.evaluate(() => window.referralTestState.encounterReferralForm.program_id), '');
    assert.equal(await page.locator('#encounterReferralProgramInfo').isVisible(), false);
    assert.deepEqual(errors, []);
});

test('the network guard blocks a signing POST to an unrelated HTTP host', async t => {
    const { page, unexpectedRequests } = await mount(t, false, 1440, false, false, false, true);
    await page.evaluate(() => fetch('http://outside.test/sign', { method: 'POST' }).catch(() => null));
    assert.deepEqual(unexpectedRequests, [{ method: 'POST', url: 'http://outside.test/sign' }]);
});

test('the fixture rejects same-host requests with unrecognized full paths', async t => {
    const { page, unexpectedRequests } = await mount(t, false, 1440, false, false, false, true);
    const asset = Array.from(fixtureAssets.keys()).find(url => url.endsWith('.svg'));
    assert.ok(asset, 'fixture includes a known SVG path');
    const aliases = ['/sign/', '/wrong/' + asset.split('/').at(-1)];
    for (const url of aliases) await page.evaluate(url => fetch(url).catch(() => null), url);
    assert.deepEqual(unexpectedRequests, aliases.map(url => ({ method: 'GET', url: 'http://referral-ui.test' + url })));
});

test('Add referral restores a saved note after returning to the list with browser Back', async t => {
    const { page, errors, blockedRequests } = await mount(t, false, 1440, 'prototype', true);
    const form = page.locator('[data-referral-prototype-form]');
    const review = page.locator('[data-referral-prototype-review]');
    const add = review.getByRole('button', { name: 'Додати направлення', exact: true });
    const note = form.locator('#encounterReferralNote');
    await form.getByRole('button', { name: 'Створити направлення', exact: true }).click();
    await add.click();
    await note.fill('Примітка для наступного направлення');
    await form.getByRole('button', { name: 'Зберегти', exact: true }).click();
    await page.goBack();
    await add.click();
    await page.waitForFunction(() => document.querySelector('#encounterReferralNote').value === 'Примітка для наступного направлення');
    assert.deepEqual(blockedRequests, []);
    assert.deepEqual(errors, []);
});
