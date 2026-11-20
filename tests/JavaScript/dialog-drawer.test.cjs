const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { buildSync } = require('esbuild');
const { chromium } = require('playwright');

// Real Blade shells and Alpine UI/focus, with synthetic form/search data.
// API searches and Livewire hydration are outside this component regression test.
const fixtures = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
    'tests/Browser/render-dialog-drawers.php',
], { encoding: 'utf8', timeout: 30000 }));
const alpine = buildSync({
    stdin: {
        contents: `import Alpine from 'alpinejs';
            import ui from '@alpinejs/ui';
            import focus from '@alpinejs/focus';
            Alpine.plugin([focus, ui]);
            Alpine.magic('wire', el => ({
                set(name, value) { Alpine.$data(el)[name] = value; }
            }));
            window.Alpine = Alpine; Alpine.start();`,
        resolveDir: process.cwd(),
    },
    bundle: true, write: false, format: 'iife',
}).outputFiles[0].text;

const styles = `
    [x-cloak] { display: none !important; }
    .fixed { position: fixed; } .left-0 { left: 0; } .right-0 { right: 0; }
    .top-0 { top: 0; } .bottom-0 { bottom: 0; } [class~="top-[57px]"] { top: 57px; }
    .w-full { width: 100%; } .h-full { height: 100%; }
    [class~="sm:w-4/5"] { width: 80%; }
    [class~="sm:w-[calc(80%-30px)]"] { width: calc(80% - 30px); }
    [class~="sm:w-[calc(80%-60px)]"] { width: calc(80% - 60px); }
    input, button { display: block; margin: 12px; padding: 8px; }
    [x-dialog\\:panel] { background: white; }
    * { transition-duration: 0s !important; animation-duration: 0s !important; }
`;
let browser;

before(async () => {
    browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    });
});
after(async () => { await browser?.close(); });

async function state(page, fixture) {
    return page.evaluate(({ formState, searchState }) => {
        const data = Alpine.$data(document.querySelector('#fixture'));
        return { form: data[formState], search: data[searchState] };
    }, fixture);
}

async function expectState(page, fixture, expected) {
    await page.waitForFunction(({ formState, searchState, expected }) => {
        const data = Alpine.$data(document.querySelector('#fixture'));
        return data[formState] === expected.form && data[searchState] === expected.search;
    }, { ...fixture, expected }, { timeout: 2000 });
    assert.deepEqual(await state(page, fixture), expected);
}

for (const [kind, fixture] of Object.entries(fixtures)) {
    for (const dismissal of ['cancel', 'escape', 'backdrop', 'outer-area', 'select']) {
        test(`${kind}: repeated search preserves the form through ${dismissal}`, async (t) => {
            const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
            t.after(() => page.close());
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.setContent(`<style>${styles}</style>${fixture.html}`);
            await page.addScriptTag({ content: alpine });
            await page.locator('#start').click();
            await page.locator('#description').fill('Existing prescription');
            await page.locator('#quantity').fill('7');
            await page.locator('#period').fill('29.10.2026');

            // Run twice to check that focus and open state survive returning/reopening.
            for (let attempt = 0; attempt < 2; attempt++) {
                await page.locator('#reopen').click();
                await expectState(page, fixture, { form: true, search: true });
                await page.locator('#query').click();
                await page.locator('#query').fill('Search term');
                await page.locator('#query').press('Enter');
                if (!(await page.locator('#filter-value').isVisible())) {
                    await page.locator('#filter').click();
                }
                await page.locator('#filter-value').fill('Filter term');
                await page.locator('#filter').click();
                await page.locator('#next').click();
                assert.equal(await page.locator('#page').textContent(), String(attempt + 2));
                await expectState(page, fixture, { form: true, search: true });

                if (dismissal === 'cancel') await page.locator('#cancel').click();
                if (dismissal === 'escape') await page.locator('#query').press('Escape');
                const searchDrawer = page.locator(`[id$="search-drawer-right"]`);
                if (dismissal === 'backdrop') {
                    const panel = await searchDrawer.locator('[x-dialog\\:panel]').boundingBox();
                    await page.mouse.click(panel.x / 2, 800);
                }
                // Exercise the root shield even when the backdrop fills its width.
                if (dismissal === 'outer-area') await searchDrawer.dispatchEvent('click');
                if (dismissal === 'select') await page.locator('#select').click();
                await expectState(page, fixture, { form: true, search: false });
                for (const [id, value] of Object.entries({
                    description: 'Existing prescription', quantity: '7', period: '29.10.2026',
                })) assert.equal(await page.locator(`#${id}`).inputValue(), value);
                assert.equal(await page.locator('#product').textContent(),
                    dismissal === 'select' ? 'selected' : 'original');
                await page.waitForFunction(() =>
                    document.activeElement?.id === 'reopen', null, { timeout: 2000 });
            }

            // Parent dismissal still works once the child has closed.
            await page.locator('#description').press('Escape');
            await expectState(page, fixture, { form: false, search: false });
            assert.deepEqual(errors, []);
        });
    }
}
