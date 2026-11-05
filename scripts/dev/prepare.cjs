const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

// Laravel prefers public/hot over the build manifest. Remove a stale local
// marker only after a refused connection; preserve active or uncertain servers.
async function prepareBuild() {
    const hotFile = path.resolve(__dirname, '../../public/hot');
    let marker;
    try {
        marker = fs.readFileSync(hotFile, 'utf8');
    } catch (error) {
        if (error.code === 'ENOENT') return;
        throw error;
    }

    const cannotVerify = 'Cannot verify the Vite server in public/hot. Stop Vite and remove public/hot before running npm run build.';
    let server;
    try {
        server = new URL(marker.trim());
    } catch {
        throw new Error(cannotVerify);
    }

    const host = server.hostname.replace(/^\[|\]$/g, '');
    if (!['http:', 'https:'].includes(server.protocol)
        || !['localhost', '127.0.0.1', '::1', '0.0.0.0'].includes(host)) {
        throw new Error(cannotVerify);
    }

    await new Promise((resolve, reject) => {
        const socket = net.createConnection({
            host: host === '0.0.0.0' ? '127.0.0.1' : host,
            port: Number(server.port || (server.protocol === 'https:' ? 443 : 80)),
        });
        socket.setTimeout(3000);
        socket.once('connect', () => {
            socket.destroy();
            reject(new Error('A server is still listening at the Vite address. Stop npm run dev before running npm run build.'));
        });
        socket.once('error', error => {
            socket.destroy();
            if (error.code === 'ECONNREFUSED') resolve();
            else reject(new Error(cannotVerify));
        });
        socket.once('timeout', () => {
            socket.destroy();
            reject(new Error(cannotVerify));
        });
    });

    // Do not remove a marker replaced by another process during the check.
    try {
        if (fs.readFileSync(hotFile, 'utf8') !== marker) {
            throw new Error('public/hot changed during preparation. Stop Vite before running npm run build.');
        }
        fs.unlinkSync(hotFile);
    } catch (error) {
        if (error.code === 'ENOENT') return;
        throw error;
    }
    console.log('Removed stale public/hot; Laravel will use the fresh build.');
}

prepareBuild().catch(error => {
    console.error(error.message);
    process.exitCode = 1;
});
