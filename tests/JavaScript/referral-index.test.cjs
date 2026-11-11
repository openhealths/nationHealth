const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { buildSync } = require('esbuild');
const { chromium } = require('playwright');

// Requires the isolated testing DB and a production build. Real Blade, Alpine,
// Livewire snapshots and morphing; API responses come from rolled-back fixtures.
let fixtures, browser, css, registration;
before(async () => {
    const phpunitConfiguration = process.env.PHPUNIT_CONFIGURATION || process.env.PHPUNIT_CONFIG;
    assert.ok(phpunitConfiguration, 'Set PHPUNIT_CONFIGURATION to an explicitly guarded isolated source/database configuration.');
    execFileSync(process.env.PHP_BINARY || 'php', [
        'vendor/bin/phpunit', '-c', phpunitConfiguration, 'tests/Browser/ReferralIndexBrowserFixture.php', '--filter', 'testExportBrowserFixture',
    ], { env: { ...process.env, PGOPTIONS: '-c timezone=UTC' }, timeout: 120000 });
    fixtures = JSON.parse(fs.readFileSync('storage/cli-testing/referral-browser-fixtures.json', 'utf8'));
    const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
    css = fs.readFileSync('public/build/' + manifest['resources/css/app.css'].file, 'utf8');
    registration = buildSync({ entryPoints: ['resources/js/index.js'], bundle: true, write: false, format: 'iife' }).outputFiles[0].text;
    browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
            : process.platform === 'win32' ? { channel: 'chrome' } : {}),
    });
});
after(async () => { await browser?.close(); });

async function mount(t, fixture = 'search', width = 1440, dark = false) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    t.after(() => page.close());
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://referral.test/**', async route => {
        if (route.request().url().endsWith('/livewire/update')) {
            const payload = route.request().postDataJSON();
            calls.push(payload);
            const method = payload.components[0].calls[0]?.method;
            const state = fixtures[method === 'process' ? 'use' : method === 'confirmUse' ? (payload.components[0].updates.selectedExecutorId ? 'process' : 'useInvalid') : method === 'search' ? 'search' : 'refresh'];
            return route.fulfill({ json: { components: [{ snapshot: JSON.stringify(state.snapshot), effects: state.effects }], assets: [] } });
        }
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html>
            <html class="${dark ? 'dark' : ''}"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture"></head>
            <body class="bg-white dark:bg-gray-800"><main class="p-4 md:ml-78 pt-20">${fixtures[fixture].html}</main></body></html>` });
    });
    await page.goto('http://referral.test/');
    await page.addStyleTag({ content: css + '\n[x-cloak]{display:none!important}' });
    await page.evaluate(() => { window.livewireScriptConfig = { csrf: 'fixture', uri: '/livewire/update' }; });
    await page.addScriptTag({ path: 'vendor/livewire/livewire/dist/livewire.js' });
    await page.addScriptTag({ content: registration });
    await page.evaluate(() => Livewire.start());
    await page.waitForTimeout(450);
    assert.deepEqual(errors, [], 'The native referral fixture must initialize without JavaScript errors.');
    return { page, calls, errors };
}

async function selectStatus(page, value) {
    await page.locator('#status').click();
    await page.locator(`input[type=checkbox][value="${value}"]`).check();
    await page.locator('label[for=patient]').click();
}

async function visibleIds(page) {
    await page.evaluate(() => new Promise(resolve => Alpine.nextTick(resolve)));
    await page.waitForTimeout(40);
    return page.locator('tbody[data-referral-id]:visible').evaluateAll(rows => rows.map(row => row.dataset.referralId));
}

test('local Ukrainian search, aliases, reset and in_progress exclude queued/terminal/draft records without requests', async t => {
    const { page, calls, errors } = await mount(t);
    await selectStatus(page, 'in_progress');
    assert.deepEqual(await visibleIds(page), ['working', 'program', 'new_program'].map(alias => fixtures.ids[alias]));
    await page.locator('#patient').fill('  ШЕВЧЕНКО  ');
    assert.deepEqual(await visibleIds(page), ['working', 'program', 'new_program'].map(alias => fixtures.ids[alias]));
    await page.locator('#patient').fill('Немає пацієнта');
    assert.deepEqual(await visibleIds(page), []);
    assert.equal(await page.getByText('Нічого не знайдено').isVisible(), true);
    await page.locator('button').filter({ hasText: 'Скинути фільтри' }).click();
    assert.equal((await visibleIds(page)).length, 10);
    assert.equal(await page.locator('#patient').inputValue(), '');
    assert.equal(await page.locator('#requisition').inputValue(), '');
    await selectStatus(page, 'entered_in_error');
    await selectStatus(page, 'revoked');
    assert.deepEqual(await visibleIds(page), ['error', 'recalled'].map(alias => fixtures.ids[alias]));
    assert.deepEqual(calls, []);
    assert.deepEqual(errors, []);
});

test('number mask submits the existing search and filtering survives a real Livewire refresh', async t => {
    const { page, calls, errors } = await mount(t, 'initial');
    await page.locator('#requisition').fill('abcd 1234 efgh-5678extra');
    assert.equal(await page.locator('#requisition').inputValue(), 'ABCD-1234-EFGH-5678');
    assert.equal(calls.length, 0);
    await page.locator('button[type=submit]').click();
    await page.waitForFunction(() => document.querySelectorAll('tbody[data-referral-id]').length === 10);
    assert.equal(calls[0].components[0].calls[0].method, 'search');
    assert.equal(calls[0].components[0].updates.requisition, 'ABCD-1234-EFGH-5678');
    await selectStatus(page, 'in_progress');
    await page.locator('#patient').fill('ШЕВЧЕНКО');
    await page.evaluate(() => Livewire.first().$refresh());
    await page.waitForTimeout(400);
    assert.deepEqual(await visibleIds(page), ['working', 'program', 'new_program'].map(alias => fixtures.ids[alias]));
    assert.equal(await page.locator('#patient').inputValue(), 'ШЕВЧЕНКО');
    assert.deepEqual(errors, []);
});

for (const width of [390, 768, 1440]) {
    for (const dark of [false, true]) {
        for (const fixture of ['single', 'search']) {
            test(`${width}px ${dark ? 'dark' : 'light'} ${fixture}: last-row menu escapes scrolling and keeps actions after morph`, async t => {
                const { page, calls, errors } = await mount(t, fixture, width, dark);
                const scroll = page.locator('[data-referrals-scroll]');
                const dimensions = await page.evaluate(() => ({ width: innerWidth, body: document.documentElement.scrollWidth }));
                assert.ok(dimensions.body <= dimensions.width, JSON.stringify(dimensions));
                await scroll.evaluate(el => { el.scrollLeft = el.scrollWidth; });
                const button = page.locator(`tbody[data-referral-id="${fixtures.ids.ready}"] button[data-referral-action]`);
                await button.click();
                const menu = page.locator(`[data-referral-menu="${fixtures.ids.ready}"]`);
                await menu.waitFor({ state: 'visible' });
                const box = await menu.boundingBox();
                assert.ok(box.x >= 0 && box.x + box.width <= width);
                assert.ok(box.y >= 0 && box.y + box.height <= 900);
                assert.equal(await menu.evaluate(el => el.parentElement === document.body), true);
                if (process.env.REFERRAL_SCREENSHOTS) {
                    await page.screenshot({ path: `storage/cli-testing/referral-menu-${width}-${dark ? 'dark' : 'light'}-${fixture}.png`, fullPage: true });
                }
                for (const item of await menu.locator('button:not([disabled])').all()) {
                    const rect = await item.boundingBox();
                    assert.ok(rect && rect.y >= box.y && rect.y + rect.height <= box.y + box.height + 1);
                    assert.equal(await item.evaluate(el => {
                        const r = el.getBoundingClientRect();
                        return el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
                    }), true);
                }
                await page.evaluate(() => window.scrollBy(0, -40));
                await page.waitForTimeout(100);
                await menu.locator('button').filter({ hasText: 'Відмінити направлення' }).click();
                await page.waitForTimeout(150);
                assert.deepEqual(calls.at(-1).components[0].calls[0], { path: '', method: 'openRegisteredAction', params: [fixtures.ids.ready, 'recall'] });
                await button.click();
                await menu.locator('button').filter({ hasText: 'Позначити помилковим' }).click();
                await page.waitForTimeout(150);
                assert.deepEqual(calls.at(-1).components[0].calls[0], { path: '', method: 'openRegisteredAction', params: [fixtures.ids.ready, 'error'] });
                await button.click();
                await page.evaluate(() => Livewire.first().$refresh());
                await page.waitForTimeout(350);
                await menu.waitFor({ state: 'visible' });
                assert.equal(await menu.count(), 1);
                await menu.locator('button:not([disabled])').first().focus();
                await page.keyboard.press('Escape');
                await menu.waitFor({ state: 'hidden' });
                await button.click();
                await menu.locator('button').filter({ hasText: 'Взяти в роботу' }).click();
                await page.locator('#referralExecutor').waitFor({ state: 'visible' });
                assert.deepEqual(calls.at(-1).components[0].calls[0], { path: '', method: 'process', params: [fixtures.ids.ready, fixtures.patientUuid] });
                const useForm = page.locator('form[wire\\:submit="confirmUse"]');
                await useForm.locator('button[type=submit]').click();
                await page.locator('#referralExecutor').waitFor({ state: 'visible' });
                await page.getByText(fixtures.executorError, { exact: true }).waitFor({ state: 'visible' });
                await page.locator('#referralExecutor').selectOption(String(fixtures.executorId));
                await useForm.locator('button[type=submit]').click();
                await page.waitForFunction(id => document.querySelector(`tbody[data-referral-id="${id}"]`)?.textContent.includes('У роботі'), fixtures.ids.ready);
                await scroll.evaluate(el => { el.scrollLeft = el.scrollWidth; });
                await page.locator(`tbody[data-referral-id="${fixtures.ids.ready}"] button[data-referral-action]`).click();
                const updatedMenu = page.locator(`[data-referral-menu="${fixtures.ids.ready}"]`);
                await updatedMenu.waitFor({ state: 'visible' });
                assert.equal(await updatedMenu.locator('button[wire\\:click^="openCompleteModal"]').isVisible(), true);
                assert.equal(await updatedMenu.locator('button[wire\\:click^="openCancelModal"]').isVisible(), true);
                assert.equal(await updatedMenu.count(), 1);
                assert.deepEqual(errors, []);
            });
        }
    }
}
