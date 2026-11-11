const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { compile } = require('@tailwindcss/node');
const { chromium } = require('playwright');

// Compile the actual application CSS, including responsive.css and legacy @apply rules.
const root = path.resolve(__dirname, '../..');
const responsive = readFileSync(path.join(root, 'resources/js/responsive.js'), 'utf8');
const candidates = [
    'flex', 'flex-nowrap', 'grid', 'grid-cols-2', 'col-span-2', 'min-w-56',
    'whitespace-nowrap', 'relative', 'absolute', 'right-0', 'w-56', 'w-full',
];
let browser;
let css;

before(async () => {
    const source = readFileSync(path.join(root, 'resources/css/app.css'), 'utf8')
        .replace(/^@import url.*\r?\n/m, '');
    const compiler = await compile(source, {
        base: path.join(root, 'resources/css'), onDependency() {},
    });
    css = compiler.build(candidates);
    browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}),
    });
});
after(async () => { await browser?.close(); });

async function settle(page) {
    await page.evaluate(() => new Promise(resolve => {
        requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    }));
}

async function createPage(t, width, html) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    t.after(() => page.close());
    // Keep local component tests independent of external font/image downloads.
    await page.route(/^https?:/, route => route.abort());
    await page.setContent(html);
    await page.addStyleTag({ content: css });
    await page.evaluate(() => document.fonts.ready);
    return page;
}

for (const width of [375, 768, 1024, 1280]) {
    for (const scope of ['responsive-content', 'modal-content']) {
        test(`${scope} at ${width}px: utilities and icon-anchored menus remain usable`, async t => {
            const page = await createPage(t, width, `
                <main class="${scope}">
                    <div class="button-group"><button id="nowrap" class="whitespace-nowrap">Синхронізувати з ЕСОЗ</button></div>
                    <div id="flex" class="flex flex-nowrap"><span>One</span><span>Two</span></div>
                    <div id="grid" class="grid grid-cols-2"><div id="span" class="col-span-2">Field</div><div>Field</div></div>
                    <div id="minimum" class="min-w-56">Minimum width</div>
                    <div class="table-scroll"><table class="table-input"><tbody><tr>
                        <td>Документ</td><td style="width:24px"><div class="relative">
                            <button style="width:24px">⋮</button>
                            <div class="absolute" style="left:50%">
                                <div id="panel" class="dropdown-panel relative" style="left:-50%">
                                    <button>Редагувати</button><button>Видалити</button>
                                </div>
                            </div>
                        </div></td>
                    </tr></tbody></table></div>
                    <div class="relative" style="width:40px;margin-left:240px">
                        <button style="width:40px">⋮</button>
                        <div id="referrals" class="dropdown-menu right-0 w-56">
                            <button class="dropdown-item">Переглянути деталі</button>
                        </div>
                    </div>
                </main>`);
            await page.addScriptTag({ content: responsive });
            await settle(page);
            const state = await page.evaluate(() => {
                const style = id => getComputedStyle(document.getElementById(id));
                const panel = document.querySelector('#panel button');
                return {
                    nowrap: style('nowrap').whiteSpace,
                    flex: style('flex').flexWrap,
                    columns: style('grid').gridTemplateColumns.split(' ').length,
                    span: style('span').gridColumn,
                    minWidth: style('minimum').minWidth,
                    panelWidth: document.getElementById('panel').getBoundingClientRect().width,
                    panelHeight: panel.getBoundingClientRect().height,
                    lineHeight: parseFloat(getComputedStyle(panel).lineHeight),
                    menuWidth: document.getElementById('referrals').getBoundingClientRect().width,
                };
            });
            assert.equal(state.nowrap, 'nowrap');
            assert.equal(state.flex, 'nowrap');
            assert.equal(state.columns, 2);
            assert.equal(state.span, 'span 2 / span 2');
            assert.equal(state.minWidth, '224px');
            assert.ok(state.panelWidth > 80, JSON.stringify(state));
            assert.ok(state.panelHeight < state.lineHeight * 2.5, JSON.stringify(state));
            assert.equal(state.menuWidth, 224);
        });
    }
}

test('table observers ignore unrelated DOM changes and handle rows, morphs, navigation and breakpoints', async t => {
    const page = await createPage(t, 768, `
        <main class="responsive-content" id="content">
            <div id="unrelated"><input id="search"><div id="calendar"></div><div id="preloader"></div></div>
            <div class="table-scroll" id="first" style="width:300px"><table id="first-table"><tbody><tr><td>Short</td></tr></tbody></table></div>
            <div class="table-scroll" id="second" style="width:300px"><table id="second-table"><tbody><tr><td>Short</td></tr></tbody></table></div>
        </main>`);
    await page.evaluate(() => {
        window.hooks = {};
        window.Livewire = { hook(name, callback) { window.hooks[name] = callback; } };
        window.work = { scans: 0, reads: [] };
        const query = Document.prototype.querySelectorAll;
        Document.prototype.querySelectorAll = function(selector) {
            if (selector.includes('table-scroll') || selector.includes('daterangepicker-uk')) work.scans++;
            return query.call(this, selector);
        };
        const scroll = Object.getOwnPropertyDescriptor(Element.prototype, 'scrollWidth');
        Object.defineProperty(Element.prototype, 'scrollWidth', {
            ...scroll,
            get() {
                if (this.tagName === 'TABLE') work.reads.push(this.id);
                return scroll.get.call(this);
            },
        });
    });
    await page.addScriptTag({ content: responsive });
    await settle(page);
    assert.equal(await page.locator('#first').evaluate(el => el.classList.contains('table-scroll-fits')), true);
    await page.evaluate(() => {
        work.scans = 0; work.reads = [];
        for (let i = 0; i < 30; i++) {
            document.getElementById('search').value = String(i);
            document.getElementById('calendar').replaceChildren(document.createElement('span'));
            document.getElementById('preloader').replaceChildren(document.createElement('span'));
        }
    });
    await settle(page);
    assert.deepEqual(await page.evaluate(() => work), { scans: 0, reads: [] });

    await page.evaluate(() => {
        document.querySelector('#first-table td').textContent = 'W'.repeat(200);
    });
    await settle(page);
    const rowWork = await page.evaluate(() => work);
    assert.equal(rowWork.scans, 0);
    assert.ok(rowWork.reads.includes('first-table'));
    assert.ok(!rowWork.reads.includes('second-table'), JSON.stringify(rowWork));
    assert.equal(await page.locator('#first').evaluate(el => el.classList.contains('table-scroll-fits')), false);

    await page.evaluate(() => {
        document.getElementById('first').remove();
        document.getElementById('second').innerHTML = '<table id="replacement"><tbody><tr><td>New table</td></tr></tbody></table>';
        const added = document.createElement('div');
        added.className = 'table-scroll'; added.id = 'added'; added.style.width = '300px';
        added.innerHTML = '<table id="added-table"><tbody><tr><td>Added table</td></tr></tbody></table>';
        document.getElementById('content').append(added);
        hooks.morphed();
    });
    await settle(page);
    assert.equal(await page.locator('#second').evaluate(el => el.classList.contains('table-scroll-fits')), true);
    assert.equal(await page.locator('#added').evaluate(el => el.classList.contains('table-scroll-fits')), true);

    await page.evaluate(() => {
        document.getElementById('content').innerHTML = '<div class="table-scroll" id="navigated"><table><tbody><tr><td>Navigation</td></tr></tbody></table></div>';
        document.dispatchEvent(new Event('livewire:navigated'));
    });
    await settle(page);
    assert.equal(await page.locator('#navigated').evaluate(el => el.classList.contains('table-scroll-fits')), true);
    await page.setViewportSize({ width: 1280, height: 900 });
    await settle(page);
    assert.equal(await page.locator('#navigated').evaluate(el => el.classList.contains('table-scroll-fits')), false);
    assert.equal(await page.locator('#navigated').evaluate(el => getComputedStyle(el).display), 'contents');
    await page.setViewportSize({ width: 375, height: 900 });
    await settle(page);
    assert.equal(await page.locator('#navigated').evaluate(el => el.classList.contains('table-scroll-fits')), true);
});

test('Flatpickr changes month count across mobile, tablet and desktop', async t => {
    const page = await createPage(t, 375, '<main class="responsive-content"><input class="daterangepicker-uk"></main>');
    await page.addStyleTag({ content: readFileSync(require.resolve('flatpickr/dist/flatpickr.css'), 'utf8') });
    await page.addScriptTag({ content: readFileSync(require.resolve('flatpickr'), 'utf8') });
    await page.evaluate(() => flatpickr('input', { mode: 'range', showMonths: 2 }));
    await page.addScriptTag({ content: responsive });
    await settle(page);
    for (const [width, months] of [[375, 1], [768, 2], [1280, 2], [375, 1]]) {
        await page.setViewportSize({ width, height: 900 });
        await settle(page);
        assert.equal(await page.locator('.daterangepicker-uk').evaluate(el => el._flatpickr.config.showMonths), months);
    }
});
