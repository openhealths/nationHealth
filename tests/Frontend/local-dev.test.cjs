const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { test } = require('node:test');

const source = path.resolve(__dirname, '../../scripts/dev/prepare.cjs');

function fixture(t) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ohealth-local-dev-'));
    t.after(() => {
        assert.equal(path.dirname(root), path.resolve(os.tmpdir()));
        fs.rmSync(root, { recursive: true, force: true });
    });
    fs.mkdirSync(path.join(root, 'scripts/dev'), { recursive: true });
    fs.mkdirSync(path.join(root, 'public'));
    const script = path.join(root, 'scripts/dev/prepare.cjs');
    fs.copyFileSync(source, script);
    const hot = path.join(root, 'public/hot');
    return { hot, run: () => {
        const result = spawnSync(process.execPath, [script], { encoding: 'utf8' });
        if (result.error) throw result.error;
        return result;
    } };
}

async function listen() {
    const server = net.createServer(socket => socket.end());
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    return server;
}

test('preparation succeeds without a Vite marker', t => {
    const { run } = fixture(t);
    assert.equal(run().status, 0);
});

test('a refused local connection allows removal of a stale Vite marker', async t => {
    const { hot, run } = fixture(t);
    const server = await listen();
    const port = server.address().port;
    await new Promise(resolve => server.close(resolve));
    fs.writeFileSync(hot, `http://127.0.0.1:${port}`);
    const result = run();
    assert.equal(result.status, 0, result.stderr);
    assert.equal(fs.existsSync(hot), false);
});

test('an active local server is preserved and stops preparation', async t => {
    const { hot, run } = fixture(t);
    const server = await listen();
    t.after(() => new Promise(resolve => server.close(resolve)));
    const marker = `http://127.0.0.1:${server.address().port}`;
    fs.writeFileSync(hot, marker);
    const result = run();
    assert.equal(result.status, 1);
    assert.match(result.stderr, /Stop npm run dev/);
    assert.equal(fs.readFileSync(hot, 'utf8'), marker);
});

for (const marker of ['invalid URL', 'https://shared-vite.example:5173', 'file:///tmp/vite']) {
    test(`an unverifiable marker is preserved: ${marker}`, t => {
        const { hot, run } = fixture(t);
        fs.writeFileSync(hot, marker);
        const result = run();
        assert.equal(result.status, 1);
        assert.match(result.stderr, /Cannot verify/);
        assert.equal(fs.readFileSync(hot, 'utf8'), marker);
    });
}
