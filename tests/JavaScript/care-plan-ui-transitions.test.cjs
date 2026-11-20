const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { buildSync } = require('esbuild');
const { chromium } = require('playwright');

const fixtures = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
    'tests/Browser/render-care-plan-ui-transitions.php',
], { encoding: 'utf8', timeout: 30000 }));
const alpine = buildSync({
    stdin: {
        contents: `import Alpine from 'alpinejs';
            import ui from '@alpinejs/ui';
            import focus from '@alpinejs/focus';
            import { carePlanGroundsSearch } from './resources/js/care-plan-grounds-search';
            Alpine.plugin([focus, ui]);
            window.wire = Alpine.reactive({ ...window.fixtureWire, calls: [],
                set(path, value) {
                    this.calls.push(path);
                    const keys = path.split('.');
                    let target = this;
                    for (const key of keys.slice(0, -1)) target = target[key];
                    target[keys.at(-1)] = value;
                },
                $set(path, value) { this.set(path, value); }
            });
            Alpine.magic('wire', () => window.wire);
            Alpine.data('carePlanGroundsSearch', carePlanGroundsSearch);
            window.Alpine = Alpine; Alpine.start();`,
        resolveDir: process.cwd(),
    }, bundle: true, write: false, format: 'iife',
}).outputFiles[0].text;
let browser;

before(async () => {
    browser = await chromium.launch({ headless: true,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    });
});
after(async () => { await browser?.close(); });

async function pageFor(kind) {
    const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });
    page.errors = [];
    page.on('pageerror', error => page.errors.push(error.message));
    await page.setContent(`<style>
        [x-cloak] { display: none !important; }
        .fixed { position: fixed; } .left-0 { left: 0; } .right-0 { right: 0; }
        .bottom-0 { bottom: 0; } [class~="top-[57px]"] { top: 57px; }
        .w-full { width: 100%; } .h-full { height: 100%; }
        [class~="sm:w-4/5"] { width: 80%; }
        [x-dialog\\:panel] { background: white; }
        input, button { padding: 8px; margin: 8px; }
        * { transition-duration: 0s !important; }
    </style>${fixtures[kind]}`);
    await page.evaluate((kind) => {
        window.fixtureWire = {
            request: { reason_reference: [] },
            activityForm: { id: 1, kind: kind + '_request', reason_code: 'I10' },
            showServiceDrawer: true, showMedicationFormDrawer: true,
        };
    }, kind);
    await page.route('**/*', route => route.fulfill({
        contentType: 'application/json', body: JSON.stringify([{ code: 'E11.9', description: 'Diabetes' }]),
    }));
    await page.addScriptTag({ content: alpine });
    return page;
}

test('teleported record selection closes the drawer and adds the reference once', async () => {
    const page = await pageFor('request');
    try {
        await page.locator('#open-records').click();
        const select = page.locator('body > [x-dialog] button[aria-label$=": Test diagnosis"]');
        await select.waitFor({ state: 'visible' });
        assert.equal(await select.evaluate(el => !!el.closest('#request-fixture')), false);
        await select.click();
        await page.waitForFunction(() => !Alpine.$data(document.querySelector('#request-fixture')).recordSearch);
        assert.deepEqual(await page.evaluate(() => wire.request.reason_reference), [{ type: 'condition', uuid: 'record-one' }]);
        assert.equal(await page.locator('#selected-records tbody tr').count(), 1);
        await select.dispatchEvent('click');
        assert.equal(await page.evaluate(() => wire.request.reason_reference.length), 1);
        assert.equal(await page.evaluate(() => wire.calls.length), 1);
        assert.deepEqual(page.errors, []);
    } finally { await page.close(); }
});

for (const [kind, drawer] of [['service', 'showServiceDrawer'], ['medication', 'showMedicationFormDrawer']]) {
    test(`${kind}: new and existing drafts replace diagnosis without overwriting free input`, async () => {
        const page = await pageFor(kind);
        const input = page.locator(`#${kind}-reason-code`);
        try {
            await input.waitFor({ state: 'visible' });
            assert.equal(await input.inputValue(), 'I10');
            await input.fill('Diabetes');
            await page.locator('button').filter({ hasText: 'E11.9 - Diabetes' }).click();
            assert.equal(await input.inputValue(), 'E11.9 - Diabetes');
            await page.evaluate(({ kind, drawer }) => {
                wire[drawer] = false;
                wire.activityForm = { id: null, kind: kind + '_request', reason_code: '' };
            }, { kind, drawer });
            await page.evaluate(drawer => { wire[drawer] = true; }, drawer);
            await page.waitForFunction(id => document.getElementById(id).value === '', `${kind}-reason-code`);
            await input.fill('Free query');
            await page.evaluate(() => { wire.activityForm.quantity = 3; });
            assert.equal(await input.inputValue(), 'Free query');
            assert.equal(await page.evaluate(() => wire.activityForm.reason_code), '');
            await page.evaluate(({ kind, drawer }) => {
                wire[drawer] = false;
                wire.activityForm = { id: 2, kind: kind + '_request', reason_code: 'J10' };
            }, { kind, drawer });
            await page.evaluate(drawer => { wire[drawer] = true; }, drawer);
            await page.waitForFunction(id => document.getElementById(id).value === 'J10', `${kind}-reason-code`);
            await page.evaluate(() => { wire.activityForm = { ...wire.activityForm, id: 3, reason_code: 'E11.9' }; });
            await page.waitForFunction(id => document.getElementById(id).value === 'E11.9', `${kind}-reason-code`);
            assert.deepEqual(page.errors, []);
        } finally { await page.close(); }
    });
}
