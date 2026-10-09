// Uses the real plugin's redirect header and the installed WooCommerce tracker.
// No external payment is made. CHROME_BIN selects the installed Chrome executable.
const http = require('node:http');
const { spawn, execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const wp = (...args) => execFileSync('docker', ['compose', 'exec', '-T', 'app', 'wp', 'eval-file', 'wp-content/plugins/icepay-for-woocommerce/tests/Browser/fixture.php', ...args], { encoding: 'utf8' }).trim();
const base = process.env.WORDPRESS_URL || 'http://localhost';
const chrome = process.env.CHROME_BIN || (process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : '/usr/bin/google-chrome');

(async () => {
  const fixture = JSON.parse(wp());
  let server;
  try {
    const response = await fetch(base + fixture.returnPath, { redirect: 'manual', headers: { Referer: 'https://checkout.icepay.com/' } });
    assert.equal(response.status, 302);
    const policy = response.headers.get('referrer-policy');
    assert.equal(policy, 'no-referrer');
    assert.ok(!response.headers.get('location').includes('via-icepay'));
    const scriptResponse = await fetch(base + '/wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.js');
    assert.equal(scriptResponse.status, 200);
    const sourcebuster = await scriptResponse.text();
    server = http.createServer((req, res) => {
      const url = new URL(req.url, `http://${req.headers.host}`);
      const mode = url.searchParams.get('mode');
      const port = server.address().port;
      res.setHeader('Content-Type', 'text/html');
      if (url.pathname === '/sourcebuster.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(sourcebuster); return; }
      if (url.pathname === '/redirect') {
        res.writeHead(302, { 'Referrer-Policy': policy, Location: `http://127.0.0.1:${port}/receipt?mode=${mode}` });
        res.end(); return;
      }
      if (url.pathname === '/provider') { res.end(`<script>location.href='http://127.0.0.1:${port}/redirect?mode=${mode}'</script>`); return; }
      if (url.pathname === '/referral') { res.end(`<script>location.href='http://127.0.0.1:${port}/start?mode=referral'</script>`); return; }
      if (url.pathname === '/receipt') {
        res.end(`<body><script src='/sourcebuster.js'></script><script>sbjs.init({lifetime:0.00001,session_length:30});document.body.textContent=JSON.stringify({referrer:document.referrer,source:sbjs.get.current.src,type:sbjs.get.current.typ});</script></body>`); return;
      }
      res.end(`<body><script src='/sourcebuster.js'></script><script>sbjs.init({lifetime:0.00001,session_length:30});document.cookie='sbjs_session=; path=/; max-age=0';location.href='http://localhost:${port}/provider?mode=${mode}';</script></body>`);
    });
    await new Promise(resolve => server.listen(0, '0.0.0.0', resolve));
    for (const [mode, suffix, source, type] of [ ['direct', '', '(direct)', 'typein'], ['campaign', '&utm_source=google&utm_medium=cpc&utm_campaign=sale', 'google', 'utm'], ['referral', '', 'localhost', 'referral'], ['fresh', '', '(direct)', 'typein'] ]) {
      const url = mode === 'referral' ? `http://localhost:${server.address().port}/referral`
        : mode === 'fresh' ? `http://localhost:${server.address().port}/provider?mode=fresh`
        : `http://127.0.0.1:${server.address().port}/start?mode=${mode}${suffix}`;
        const output = await new Promise((resolve, reject) => {
          const child = spawn(chrome, ['--headless', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--dump-dom', '--timeout=15000', '--virtual-time-budget=5000', url]);
          let out = ''; let err = '';
          const timer = setTimeout(() => { child.kill(); reject(new Error('Chrome timed out')); }, 30000);
          child.stdout.on('data', data => { out += data; });
          child.stderr.on('data', data => { err += data; });
          child.on('error', error => { clearTimeout(timer); reject(error); });
          child.on('close', code => { clearTimeout(timer); code === 0 ? resolve(out) : reject(new Error(err)); });
        });
        assert.ok(output.includes(`"referrer":"","source":"${source}","type":"${type}"`), output);
        console.log(`PASS: ${mode} attribution preserved; payment-return referrer empty`);
    }
  } finally {
    if (server) server.close();
    wp('delete', String(fixture.id));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
