// Exercise the built Blocks registration in Chrome without submitting a payment.
const http = require('node:http');
const fs = require('node:fs');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');

const chrome = process.env.CHROME_BIN || (process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : '/usr/bin/google-chrome');
const bundle = fs.readFileSync('build/index.js', 'utf8');
const html = `<body><script>
const methods = {};
window.wc = {wcBlocksRegistry: {registerPaymentMethod: config => methods[config.name] = config}};
window.ReactJSXRuntime = {jsx: () => null, jsxs: () => null};
window.icepay = {paymentMethods: [
 {id: 'restricted', title: 'Restricted', icon: '', restrictions: {countries: ['NL', 'BE'], minimum: '10', maximum: '20'}},
 {id: 'unrestricted', title: 'Unrestricted', icon: '', restrictions: {countries: [], minimum: '', maximum: ''}}
]};
</script><script src='/bundle.js'></script><script>
try {
 const check = (id, country, price, unit, expected) => {
  const actual = methods[id].canMakePayment({billingAddress: {country}, cartTotals: {total_price: String(price), currency_minor_unit: unit}});
  if (actual !== expected) throw new Error([id, country, price, unit, actual, expected].join(' / '));
 };
 check('restricted', 'NL', 1000, 2, true);
 check('restricted', 'BE', 2000, 2, true);
 check('restricted', 'DE', 1500, 2, false);
 check('restricted', '', 1500, 2, false);
 check('restricted', 'NL', 999, 2, false);
 check('restricted', 'NL', 2001, 2, false);
 check('restricted', 'NL', 15000, 3, true);
 check('restricted', 'NL', 15, 0, true);
 check('unrestricted', '', 0, 2, true);
 check('unrestricted', 'US', 999999, 2, true);
 document.body.textContent = 'PASS: built Blocks eligibility handles countries, limits, and currency units';
} catch (error) { document.body.textContent = 'FAIL: ' + error.message; }
</script></body>`;

(async () => {
  const server = http.createServer((req, res) => {
    res.setHeader('Content-Type', req.url === '/bundle.js' ? 'text/javascript' : 'text/html');
    res.end(req.url === '/bundle.js' ? bundle : html);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const output = await new Promise((resolve, reject) => {
      const child = spawn(chrome, ['--headless', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--dump-dom', '--virtual-time-budget=3000', `http://127.0.0.1:${server.address().port}`]);
      let out = ''; let err = '';
      const timer = setTimeout(() => { child.kill(); reject(new Error('Chrome timed out')); }, 30000);
      child.stdout.on('data', data => { out += data; });
      child.stderr.on('data', data => { err += data; });
      child.on('error', error => { clearTimeout(timer); reject(error); });
      child.on('close', code => { clearTimeout(timer); if (code === 0) { resolve(out); } else { reject(new Error(err)); } });
    });
    assert.ok(output.includes('<body>PASS: built Blocks eligibility'), output);
    console.log('PASS: built Blocks eligibility handles countries, limits, and currency units');
  } finally { server.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
