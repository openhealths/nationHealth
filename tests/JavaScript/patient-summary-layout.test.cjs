const { test, before, after } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const { execFileSync } = require("node:child_process");
const { chromium } = require("playwright");
const os = require("node:os");
const path = require("node:path");
let browser, html, css;

before(async () => {
    const directoryForRun = fs.mkdtempSync(path.join(os.tmpdir(), "patient-summary-"));
    const fixturePath = path.join(directoryForRun, "summary.html");
    try {
        execFileSync(process.env.PHP_BINARY || "php", [
            "vendor/bin/phpunit", "tests/Feature/Person/PatientSummaryViewTest.php",
            "--filter", "test_loaded_cards_and_pagination_are_still_rendered_after_a_refresh_error",
        ], {
            env: { ...process.env, SUMMARY_UI_EXPORT_PATH: fixturePath },
            stdio: "inherit",
            timeout: 60000,
        });
        html = fs.readFileSync(fixturePath, "utf8");
    } finally {
        fs.rmSync(directoryForRun, { recursive: true, force: true });
    }
    const directory = fs.existsSync(
        "storage/cli-testing/summary-layout-build/manifest.json",
    )
        ? "storage/cli-testing/summary-layout-build/"
        : "public/build/";
    const manifest = JSON.parse(
        fs.readFileSync(directory + "manifest.json", "utf8"),
    );
    css = fs
        .readFileSync(
            directory + manifest["resources/css/app.css"].file,
            "utf8",
        )
        .replace(/@import"[^"]+";/g, "");
    browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}),
    });
});
after(async () => browser?.close());
async function mount(t, dark, width) {
    const page = await browser.newPage({ viewport: { width, height: 1100 } });
    t.after(() => page.close());
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("https://**/*", (route) => route.abort());
    await page.setContent(
        '<!doctype html><html class="' +
            (dark ? "dark" : "") +
            '"><body class="bg-white dark:bg-gray-800"><main class="responsive-content p-4 lg:ml-78 pt-8">' +
            html +
            "</main></body></html>",
    );
    assert.equal(await page.evaluate(() => document.compatMode), "CSS1Compat");
    await page.evaluate(() =>
        document
            .querySelectorAll("template[x-teleport]")
            .forEach((template) => template.remove()),
    );
    await page.addStyleTag({ content: css });
    await page.evaluate(() => {
        document.addEventListener(
            "alpine:init",
            () => {
                Alpine.magic(
                    "wire",
                    () =>
                        new Proxy(
                            {},
                            {
                                get: (_, key) => {
                                    if (key === "entangle") return () => false;
                                    if (key === "intercept") return (watch) => {
                                        window.simulateSummaryFailure = (name, params = []) => {
                                            let failed;
                                            watch({
                                                action: { name, params },
                                                onSend: (cb) => cb(),
                                                onFailure: () => {},
                                                onError: (cb) => { failed = cb; },
                                            });
                                            failed?.({ response: { status: 503 }, preventDefault() {} });
                                        };
                                        return () => { delete window.simulateSummaryFailure; };
                                    };
                                    return () => {};
                                },
                            },
                        ),
                );
            },
            { once: true },
        );
    });
    await page.addScriptTag({
        path: "node_modules/@alpinejs/focus/dist/cdn.js",
    });
    await page.addScriptTag({ path: "node_modules/@alpinejs/ui/dist/cdn.js" });
    await page.addScriptTag({ path: "node_modules/alpinejs/dist/cdn.js" });
    await page.waitForFunction(() => window.Alpine !== undefined);
    return { page, errors };
}
for (const [theme, dark, width] of [
    ["light", false, 1440],
    ["dark", true, 1440],
    ["mobile", true, 390],
]) {
    test("patient summary navigation and cards in " + theme, async (t) => {
        const { page, errors } = await mount(t, dark, width);
        const go = async (label) => {
            await page.locator(".summary-sidebar-btn").filter({ hasText: label }).click();
        };

        await go("Спостереження");
        const section = page.locator("#block-observations");
        const card = section.locator("article");
        await card.waitFor({ state: "visible" });
        // The error must not displace the previously loaded record.
        await page.getByRole("alert").filter({ hasText: "Не вдалося оновити записи" }).waitFor({ state: "visible" });
        const result = card.getByText("Значення", { exact: true }).locator("..").locator("dd");
        assert.equal((await result.textContent()).trim(), "< 0 ммоль/л\nНі");
        assert.equal(await section.getByRole("button", { name: "Показати більше" }).getAttribute("wire:click"), "loadMore('observations')");
        await card.getByRole("button", { name: "Дії", exact: true }).click();
        await card.getByRole("button", { name: "Переглянути деталі", exact: true }).click();
        const drawer = page.getByRole("dialog");
        await drawer.waitFor({ state: "visible" });
        assert.match(await drawer.textContent(), /Контроль глікемії/);
        await drawer.getByRole("button", { name: "Закрити", exact: true }).click();
        await drawer.waitFor({ state: "hidden" });

        await page.evaluate(() => window.simulateSummaryFailure("loadMore", ["observations"]));
        await section.getByRole("alert").waitFor({ state: "visible" });
        assert.equal(await card.isVisible(), true);
        await page.evaluate(() => window.simulateSummaryFailure("getObservations"));
        const retry = section.getByRole("button", { name: "Повторити", exact: true });
        assert.equal(await retry.getAttribute("wire:click"), "getObservations");

        await go("Вакцинації");
        const vaccine = page.locator("#block-immunizations article");
        await vaccine.waitFor({ state: "visible" });
        assert.match(await vaccine.textContent(), /3 мл/);

        await go("Стани");
        for (const [title, status] of [["Попередній стан", "попередній"], ["Диференціальний стан", "диференціальний"]]) {
            const condition = page.locator("#block-conditions article").filter({ hasText: title });
            await condition.waitFor({ state: "visible" });
            const field = (label) => condition.locator("dt").filter({ hasText: label }).locator("..").locator("dd");
            assert.equal((await field("Джерело інформації").textContent()).trim(), "Зі слів пацієнта");
            assert.equal((await field("Статус верифікації").textContent()).trim(), status);
        }

        await go("Алергії");
        const deferred = page.locator("#block-allergies");
        await deferred.locator("[data-summary-unavailable]").waitFor({ state: "visible" });
        assert.equal(await deferred.locator("article").count(), 0);
        const serverActions = await deferred.evaluate((root) => [...root.querySelectorAll("*")].flatMap((node) =>
            [...node.attributes].filter((attr) => attr.name.startsWith("wire:click") || attr.value.includes("$wire.")).map((attr) => attr.value),
        ));
        assert.deepEqual(serverActions, []);
        const geometry = await page.evaluate(() => ({
            viewport: innerWidth,
            content: document.documentElement.scrollWidth,
            sidebarAligned: [...document.querySelectorAll(".summary-sidebar-btn")].every((node) => getComputedStyle(node).textAlign === "left"),
        }));
        assert.ok(geometry.content <= geometry.viewport + 1, JSON.stringify(geometry));
        assert.ok(geometry.sidebarAligned);
        assert.deepEqual(errors, []);
    });
}
