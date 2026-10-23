const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

function printer() {
    const frames = [];
    const calls = [];
    const document = {
        querySelector: () => frames.find((frame) => !frame.removed),
        createElement(tag) {
            assert.equal(tag, 'iframe');
            const frame = {
                attributes: {}, style: {}, events: {}, removed: false,
                setAttribute(key, value) { this.attributes[key] = value; },
                addEventListener(event, handler) { this.events[event] = handler; },
                remove() { this.removed = true; },
                contentWindow: {
                    addEventListener(event, handler) { frame.events[event] = handler; calls.push(event); },
                    focus() { calls.push('focus'); },
                    print() { calls.push('print'); },
                },
            };
            return frame;
        },
        body: { appendChild: (frame) => frames.push(frame) },
    };
    const context = { document, window: { open() { throw new Error('Printing must not open a popup'); } } };
    vm.runInNewContext(readFileSync('public/js/print-sandboxed.js', 'utf8'), context);
    return { print: context.window.printSandboxedHtml, frames, calls };
}

test('asynchronous print waits for document load and keeps the supplied content and print styles', async () => {
    const { print, frames, calls } = printer();
    await Promise.resolve();
    print('<style>@page {size:A5}</style><img src="data:image/png;base64,barcode"><p>request</p>');
    assert.deepEqual(calls, []);
    assert.match(frames[0].srcdoc, /size:A5/);
    assert.match(frames[0].srcdoc, /data:image\/png;base64,barcode/);
    assert.match(frames[0].srcdoc, /<p>request<\/p>/);
    frames[0].events.load();
    assert.deepEqual(calls, ['afterprint', 'focus', 'print']);
});

test('print document blocks scripts, embedded frames, forms and opener access', () => {
    const { print, frames } = printer();
    print('<p>request</p>');
    assert.equal(frames[0].attributes.sandbox, 'allow-same-origin allow-modals');
    assert.equal(frames[0].attributes.referrerpolicy, 'no-referrer');
    for (const directive of ['script-src', 'frame-src', 'connect-src', 'object-src', 'form-action', 'base-uri']) {
        assert.ok(frames[0].srcdoc.includes(`${directive} 'none'`));
    }
    assert.equal(frames[0].attributes['aria-hidden'], 'true');
});

test('closing print removes the frame and repeated calls remove any abandoned frame', () => {
    const { print, frames } = printer();
    print('first');
    frames[0].events.load();
    frames[0].events.afterprint();
    assert.equal(frames[0].removed, true);
    print('second');
    print('third');
    assert.equal(frames[1].removed, true);
    assert.equal(frames.filter((frame) => !frame.removed).length, 1);
});

test('print failure removes its frame without swallowing the error', () => {
    const { print, frames } = printer();
    print('request');
    frames[0].contentWindow.print = () => { throw new Error('Printing failed'); };
    assert.throws(() => frames[0].events.load(), /Printing failed/);
    assert.equal(frames[0].removed, true);
});
