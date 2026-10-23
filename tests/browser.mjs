// Browser tests for PSST: the manager UI in Chromium, public pages and sites in Chromium,
// Firefox and WebKit. Runs in the Playwright container next to the PSST container; start it
// through tests/run.sh --browser.
import { chromium, firefox, webkit } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';

const BASE = 'http://localhost';
// The test site is the checklist example from the site guide, so the example stays correct.
const CHECKLIST = Buffer.from(readFileSync('/work/sites.md', 'utf8').match(/```html\n([\s\S]*?)```/)[1]);
let failures = 0;
const consoleErrors = [];

function report(status, name, detail) {
  if (status !== 'ok') failures += 1;
  console.log(`  ${status === 'ok' ? 'ok  ' : 'FAIL'} ${name}${detail ? ` (${detail})` : ''}`);
}

let currentPage = null;
async function closeDialogs() {
  if (!currentPage || currentPage.isClosed()) return;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if (!(await currentPage.locator('.modal.show').count())) return;
    await currentPage.locator('.modal.show .btn-close').first().click();
    await currentPage.waitForTimeout(400);
  }
}

let failureShots = 0;
async function check(name, fn) {
  try {
    const result = await fn();
    if (result === false) report('FAIL', name);
    else report('ok', name, typeof result === 'string' ? result : '');
  } catch (error) {
    let detail = String(error.message).split('\n')[0].slice(0, 200);
    if (currentPage && !currentPage.isClosed()) {
      // Show what the UI said, keep a picture, and close dialogs so the next test can run.
      for (const selector of ['#storage-error', '#api-error', '#app-alert']) {
        const box = currentPage.locator(`${selector}:not(.d-none)`);
        if (await box.count()) detail += ` | ${selector}: ${(await box.textContent()).trim().slice(0, 150)}`;
      }
      failureShots += 1;
      await currentPage.screenshot({ path: `/work/shots/fail-${failureShots}.png` }).catch(() => {});
      await closeDialogs().catch(() => {});
    }
    report('FAIL', name, detail);
  }
}

function equal(actual, expected) {
  if (actual !== expected) throw new Error(`expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  return true;
}

function watch(page, label) {
  page.on('pageerror', (error) => consoleErrors.push(`${label}: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(`${label}: ${message.text()}`);
  });
  page.on('dialog', (dialog) => dialog.accept());
  return page;
}

const usedHtp = new Set();
function htp() {
  // Month in PSST's time zone; Zurich's offset is whole hours, so the minute is UTC's.
  const now = new Date();
  const month = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Zurich', month: 'numeric' }).format(now).padStart(2, '0');
  const minute = String(now.getUTCMinutes()).padStart(2, '0');
  let value;
  do {
    value = `${1 + Math.floor(Math.random() * 9)}${month}${minute}${Math.floor(Math.random() * 10)}`;
  } while (usedHtp.has(value));
  usedHtp.add(value);
  return value;
}

async function createShare(page, fill) {
  await page.click('#share-result-close').catch(() => {});
  await fill();
  await page.click('#save-button');
  await page.waitForSelector('#share-result:not(.d-none)', { timeout: 30000 });
  return {
    url: await page.inputValue('#share-result-url'),
    editor: await page.inputValue('#share-result-editor-url'),
    note: await page.textContent('#share-result-note'),
  };
}

// Probes the sandbox from inside a site page.
async function sandboxProbe(page) {
  return page.evaluate(async () => {
    const attempt = (fn) => { try { fn(); return 'allowed'; } catch (error) { return 'blocked'; } };
    const managerRead = await fetch('/manage-api.php?action=shares', { credentials: 'include' })
      .then((response) => response.text().then((text) => `readable:${response.status}:${text.slice(0, 30)}`))
      .catch(() => 'blocked');
    return {
      origin: self.origin,
      localStorage: attempt(() => localStorage.length),
      cookie: attempt(() => document.cookie),
      managerRead,
      psst: typeof window.psst,
    };
  });
}

async function addChecklistItem(page, text) {
  await page.fill('#text', text);
  await page.click('#add button');
  await page.waitForFunction((item) => document.querySelector('#items').textContent.includes(item), text, { timeout: 10000 });
}

const browser = await chromium.launch();
const context = await browser.newContext({ acceptDownloads: true, viewport: { width: 1400, height: 1000 } });
const page = watch(await context.newPage(), 'manager');
currentPage = page;
const sites = {};

console.log('== Chromium: manager');
await check('login with a valid HTP', async () => {
  await page.goto(`${BASE}/manage.php`);
  await page.fill('#login-username', 'admin');
  await page.fill('#login-password', 'psst');
  await page.fill('#login-htp', htp());
  await page.click('#login-form button[type="submit"]');
  await page.waitForSelector('#manage-view:not(.d-none)', { timeout: 15000 });
});
await page.screenshot({ path: '/work/shots/01-manager-empty.png' });

await check('create a text share and copy its link', async () => {
  const result = await createShare(page, async () => {
    await page.selectOption('#share-type', 'text');
    await page.fill('#share-title', 'Plain text');
    await page.fill('#share-text', 'hello from the browser');
  });
  sites.text = result.url;
  await page.click('#share-result-copy');
  await page.waitForSelector('#app-alert.alert-success', { timeout: 5000 });
  return equal(await page.textContent('#app-alert'), 'Copied.');
});
await check('text share opens', async () => {
  const view = watch(await context.newPage(), 'text');
  await view.goto(sites.text);
  const body = await view.textContent('body');
  await view.close();
  return equal(body.trim(), 'hello from the browser');
});

await check('encrypted share: generate password, encrypt, decrypt', async () => {
  const result = await createShare(page, async () => {
    await page.selectOption('#share-type', 'text');
    await page.fill('#share-title', 'Encrypted note');
    await page.check('#share-encrypted');
    await page.click('#generate-share-password');
    sites.password = await page.inputValue('#share-password');
    await page.fill('#share-text', 'top secret text');
  });
  sites.encrypted = result.url;
  if (!result.note.includes('password separately')) throw new Error('missing password note');
  const view = watch(await context.newPage(), 'encrypted');
  await view.goto(sites.encrypted);
  await view.fill('#password', 'wrong password');
  await view.click('#decrypt-button');
  await view.waitForSelector('#decrypt-alert:not(.d-none)');
  const wrong = await view.textContent('#decrypt-alert');
  await view.fill('#password', sites.password);
  await view.click('#decrypt-button');
  await view.waitForSelector('#decrypted-output:not(.d-none) pre', { timeout: 30000 });
  const text = await view.textContent('#decrypted-output pre');
  await view.screenshot({ path: '/work/shots/04-encrypted.png' });
  await view.close();
  if (!wrong.includes('Wrong password')) throw new Error(`wrong-password message: ${wrong}`);
  return equal(text, 'top secret text');
});

await check('markdown share renders', async () => {
  const result = await createShare(page, async () => {
    await page.selectOption('#share-type', 'markdown');
    await page.fill('#share-title', 'Release notes');
    await page.fill('#share-text', '# Release notes\n\n- **Faster** sync\n- [Docs](https://example.com)\n\n| a | b |\n|---|---|\n| 1 | 2 |');
  });
  const view = watch(await context.newPage(), 'markdown');
  await view.goto(result.url);
  const heading = await view.textContent('article h1');
  const strong = await view.textContent('article li strong');
  const cells = await view.locator('article td').count();
  await view.screenshot({ path: '/work/shots/03-markdown.png', fullPage: true });
  await view.close();
  return equal(`${heading}|${strong}|${cells}`, 'Release notes|Faster|2');
});

await check('view-limited text: click to open, then gone', async () => {
  const result = await createShare(page, async () => {
    await page.selectOption('#share-type', 'text');
    await page.fill('#share-title', 'Burn');
    await page.fill('#share-text', 'read once');
    await page.fill('#share-max-views', '1');
  });
  const view = watch(await context.newPage(), 'burn');
  await view.goto(result.url);
  const before = await view.textContent('body');
  await view.click('button[type="submit"]');
  await view.waitForSelector('#shared-text');
  const text = await view.textContent('#shared-text');
  const notice = await view.textContent('.alert-warning');
  const again = await view.goto(result.url);
  await view.close();
  if (before.includes('read once')) throw new Error('content shown before the click');
  if (!notice.includes('last view')) throw new Error('missing last-view notice');
  return equal(`${text}|${again.status()}`, 'read once|404');
});

await check('drop box: upload through the page, file shows in the manager', async () => {
  await createShare(page, async () => {
    await page.selectOption('#share-type', 'upload');
    await page.fill('#share-title', 'Inbox');
  });
  const uploadUrl = await page.inputValue('#upload-url');
  const view = watch(await context.newPage(), 'dropbox');
  await view.goto(uploadUrl);
  await view.setInputFiles('#file', { name: 'report.txt', mimeType: 'text/plain', buffer: Buffer.from('quarterly numbers') });
  await view.click('#upload-button');
  await view.waitForSelector('#upload-status.alert-success', { timeout: 15000 });
  const space = await view.textContent('#upload-space');
  await view.close();
  await page.click('#refresh-button');
  await page.waitForFunction(() => document.querySelector('#upload-file-list').textContent.includes('report.txt'), null, { timeout: 10000 });
  const usage = await page.textContent('#upload-usage-text');
  if (!space.startsWith('Space left:')) throw new Error(`space text: ${space}`);
  return equal(usage.startsWith('17 B of 1.0 MB'), true);
});
await page.click('#cancel-edit-button');

console.log('== Chromium: sites');
async function createSite(title, mode, extra) {
  return createShare(page, async () => {
    await page.selectOption('#share-type', 'site');
    await page.fill('#share-title', title);
    await page.setInputFiles('#share-site-file', { name: 'checklist.html', mimeType: 'text/html', buffer: CHECKLIST });
    await page.selectOption('#share-storage-mode', mode);
    if (extra) await extra();
  });
}

await check('publish a shared site from the manager', async () => {
  const result = await createSite('Checklist shared', 'shared');
  sites.shared = result.url;
  return equal(await page.textContent('#share-result-title'), 'Site published');
});
await page.screenshot({ path: '/work/shots/02-manager-shares.png', fullPage: true });

let sitePage;
await check('site page runs and saves to shared storage', async () => {
  sitePage = watch(await context.newPage(), 'site-shared');
  await sitePage.goto(sites.shared);
  await sitePage.waitForSelector('#items', { state: 'attached' });
  await addChecklistItem(sitePage, 'Milk');
  await addChecklistItem(sitePage, 'Bread');
  await sitePage.reload();
  await sitePage.waitForFunction(() => document.querySelectorAll('#items li').length === 2, null, { timeout: 10000 });
  await sitePage.screenshot({ path: '/work/shots/05-site.png' });
});
await check('the sandbox keeps the site away from PSST', async () => {
  const probe = await sandboxProbe(sitePage);
  return equal(JSON.stringify(probe), JSON.stringify({ origin: 'null', localStorage: 'blocked', cookie: 'blocked', managerRead: 'blocked', psst: 'object' }));
});
await check('another visitor sees shared data and can tick an item', async () => {
  const other = await browser.newContext();
  const visitor = watch(await other.newPage(), 'site-shared-2');
  await visitor.goto(sites.shared);
  await visitor.waitForFunction(() => document.querySelectorAll('#items li').length === 2, null, { timeout: 10000 });
  await visitor.locator('#items li input').first().check();
  await visitor.waitForTimeout(500);
  await other.close();
  await sitePage.reload();
  await sitePage.waitForFunction(() => document.querySelector('#items li input') && document.querySelector('#items li input').checked, null, { timeout: 10000 });
});

await check('per-visitor site: every browser has its own list', async () => {
  const result = await createSite('Checklist personal', 'visitor');
  sites.visitor = result.url;
  const first = watch(await context.newPage(), 'site-visitor-1');
  await first.goto(sites.visitor);
  await first.waitForSelector('#items', { state: 'attached' });
  await addChecklistItem(first, 'Mine only');
  const other = await browser.newContext();
  const second = watch(await other.newPage(), 'site-visitor-2');
  await second.goto(sites.visitor);
  await second.waitForSelector('#items', { state: 'attached' });
  await second.waitForTimeout(800);
  const secondItems = await second.locator('#items li').count();
  await addChecklistItem(second, 'Theirs');
  await other.close();
  await first.reload();
  await first.waitForFunction(() => document.querySelectorAll('#items li').length === 1, null, { timeout: 10000 });
  const firstText = await first.textContent('#items');
  const scope = await first.evaluate(() => window.psst.storage.scope);
  await first.close();
  return equal(`${secondItems}|${firstText.trim()}|${scope}`, '0|Mine only|visitor');
});

await check('read-only site: visitors read, the editor link writes', async () => {
  const result = await createSite('Checklist read-only', 'read');
  sites.readonly = result.url;
  if (!result.editor.includes('&edit=')) throw new Error('no editor link in the result panel');
  const editor = watch(await context.newPage(), 'site-editor');
  await editor.goto(result.editor);
  await editor.waitForSelector('#items', { state: 'attached' });
  await addChecklistItem(editor, 'Announcement');
  const editorCanWrite = await editor.evaluate(() => window.psst.storage.canWrite);
  await editor.close();
  const other = await browser.newContext();
  const visitor = watch(await other.newPage(), 'site-reader');
  await visitor.goto(sites.readonly);
  await visitor.waitForFunction(() => document.querySelector('#items').textContent.includes('Announcement'), null, { timeout: 10000 });
  const outcome = await visitor.evaluate(async () => {
    try { await window.psst.storage.append('items', { text: 'spam', done: false }); return 'written'; } catch (error) { return `refused:${error.status}`; }
  });
  const boxDisabled = await visitor.locator('#items li input').first().isDisabled();
  await other.close();
  return equal(`${editorCanWrite}|${outcome}|${boxDisabled}`, 'true|refused:403|true');
});

console.log('== Chromium: manager storage and API dialogs');
await check('storage dialog: edit a value with its version', async () => {
  await page.click('#share-result-close');
  await page.locator('#shares-table tr', { hasText: 'Checklist shared' }).getByRole('button', { name: 'Storage' }).click();
  await page.waitForSelector('#storage-modal.show');
  await page.waitForFunction(() => document.querySelector('#storage-items').textContent.includes('items'), null, { timeout: 10000 });
  await page.locator('#storage-items tr', { hasText: 'items' }).getByRole('button', { name: 'Edit' }).click();
  await page.waitForFunction(() => document.querySelector('#storage-value').value.includes('Milk'));
  const value = JSON.parse(await page.inputValue('#storage-value'));
  value.push({ text: 'Added by the owner', done: false });
  await page.fill('#storage-value', JSON.stringify(value));
  await page.click('#storage-editor button[type="submit"]');
  await page.waitForSelector('#storage-error.alert-success', { timeout: 10000 });
  await page.screenshot({ path: '/work/shots/06-storage-dialog.png' });
  const message = await page.textContent('#storage-error');
  await sitePage.reload();
  await sitePage.waitForFunction(() => document.querySelector('#items').textContent.includes('Added by the owner'), null, { timeout: 10000 });
  return equal(message.startsWith('Saved "items"'), true);
});
await check('storage dialog: stale edit is refused', async () => {
  await sitePage.evaluate(() => window.psst.storage.increment('visits'));
  await page.click('#storage-editor-new');
  await page.locator('#storage-items tr', { hasText: 'visits' }).count();
  await page.click('#storage-refresh');
  await page.waitForFunction(() => document.querySelector('#storage-items').textContent.includes('visits'), null, { timeout: 10000 });
  await page.locator('#storage-items tr', { hasText: 'visits' }).getByRole('button', { name: 'Edit' }).click();
  await page.waitForFunction(() => document.querySelector('#storage-value').value.trim() === '1');
  await sitePage.evaluate(() => window.psst.storage.increment('visits'));
  await page.fill('#storage-value', '100');
  await page.click('#storage-editor button[type="submit"]');
  await page.waitForSelector('#storage-error.alert-danger', { timeout: 10000 });
  return equal((await page.textContent('#storage-error')).includes('changed in the meantime'), true);
});
await check('storage dialog: export downloads JSON', async () => {
  const [download] = await Promise.all([page.waitForEvent('download'), page.click('#storage-export')]);
  const data = JSON.parse(readFileSync(await download.path(), 'utf8'));
  return equal(Object.keys(data.shared).sort().join(','), 'items,visits');
});
await page.click('#storage-modal .btn-close');
await page.waitForSelector('#storage-modal', { state: 'hidden' });

await check('storage dialog: visitor stores of the per-visitor site', async () => {
  await page.locator('#shares-table tr', { hasText: 'Checklist personal' }).getByRole('button', { name: 'Storage' }).click();
  await page.waitForSelector('#storage-modal.show');
  await page.waitForFunction(() => document.querySelectorAll('#storage-store option').length === 3, null, { timeout: 10000 });
  const selected = await page.inputValue('#storage-store');
  await page.waitForFunction(() => document.querySelector('#storage-items').textContent.includes('items'), null, { timeout: 10000 });
  await page.click('#storage-delete-visitor');
  await page.waitForFunction(() => document.querySelectorAll('#storage-store option').length === 2, null, { timeout: 10000 });
  await page.click('#storage-modal .btn-close');
  await page.waitForSelector('#storage-modal', { state: 'hidden' });
  return equal(selected.length, 32);
});

await check('API dialog: create and revoke a key', async () => {
  await page.click('#api-button');
  await page.waitForSelector('#api-modal.show');
  await page.fill('#api-key-name', 'PLM browser test');
  await page.click('#api-key-form button[type="submit"]');
  await page.waitForSelector('#api-new-key:not(.d-none)', { timeout: 10000 });
  const token = await page.inputValue('#api-new-key-token');
  await page.screenshot({ path: '/work/shots/07-api-dialog.png' });
  await page.locator('#api-keys-table tr', { hasText: 'PLM browser test' }).getByRole('button', { name: 'Revoke' }).click();
  await page.waitForFunction(() => document.querySelector('#api-keys-table').textContent.includes('Revoked'), null, { timeout: 10000 });
  await page.click('#api-modal .btn-close');
  await page.waitForSelector('#api-modal', { state: 'hidden' });
  await page.waitForFunction(() => document.querySelector('#api-new-key-token').value === '', null, { timeout: 3000 });
  const cleared = await page.inputValue('#api-new-key-token');
  return equal(`${token.startsWith('psst_')}|${token.length}|${cleared}`, 'true|48|');
});

await check('share list filter', async () => {
  const all = await page.locator('#shares-table tr').count();
  await page.fill('#share-filter', 'checklist');
  const filtered = await page.locator('#shares-table tr').count();
  await page.fill('#share-filter', '');
  return equal(`${all > filtered}|${filtered}`, 'true|3');
});

await check('edit a site keeps its storage mode and shows the editor link', async () => {
  await page.locator('#shares-table tr', { hasText: 'Checklist read-only' }).getByRole('button', { name: 'Edit' }).click();
  const mode = await page.inputValue('#share-storage-mode');
  const editor = await page.inputValue('#site-editor-url');
  const visible = await page.isVisible('#site-info');
  await page.click('#cancel-edit-button');
  return equal(`${mode}|${editor.includes('&edit=')}|${visible}`, 'read|true|true');
});

await check('expiry: custom date field appears', async () => {
  await page.selectOption('#share-expiry', 'custom');
  const visible = await page.isVisible('#share-expiry-custom');
  await page.selectOption('#share-expiry', 'never');
  return visible;
});
await page.screenshot({ path: '/work/shots/02-manager-shares.png', fullPage: true });
await browser.close();

for (const [name, engine] of [['Firefox', firefox], ['WebKit', webkit]]) {
  console.log(`== ${name}: public pages and sites`);
  const other = await engine.launch();
  const ctx = await other.newContext();
  await check(`${name}: shared site loads and saves`, async () => {
    const site = watch(await ctx.newPage(), `${name}-site`);
    await site.goto(sites.shared);
    await site.waitForFunction(() => document.querySelectorAll('#items li').length >= 3, null, { timeout: 15000 });
    await addChecklistItem(site, `From ${name}`);
    await site.reload();
    await site.waitForFunction((item) => document.querySelector('#items').textContent.includes(item), `From ${name}`, { timeout: 15000 });
    await site.close();
  });
  await check(`${name}: sandbox keeps the site away from PSST`, async () => {
    const site = watch(await ctx.newPage(), `${name}-probe`);
    await site.goto(sites.shared);
    await site.waitForSelector('#items', { state: 'attached' });
    const probe = await sandboxProbe(site);
    await site.close();
    return equal(JSON.stringify(probe), JSON.stringify({ origin: 'null', localStorage: 'blocked', cookie: 'blocked', managerRead: 'blocked', psst: 'object' }));
  });
  await check(`${name}: per-visitor storage survives a reload`, async () => {
    const site = watch(await ctx.newPage(), `${name}-visitor`);
    await site.goto(sites.visitor);
    await site.waitForSelector('#items', { state: 'attached' });
    await addChecklistItem(site, `${name} only`);
    await site.reload();
    await site.waitForFunction((item) => document.querySelector('#items').textContent.includes(item), `${name} only`, { timeout: 15000 });
    const count = await site.locator('#items li').count();
    await site.close();
    return equal(count, 1);
  });
  await check(`${name}: encrypted share decrypts`, async () => {
    const view = watch(await ctx.newPage(), `${name}-encrypted`);
    await view.goto(sites.encrypted);
    await view.fill('#password', sites.password);
    await view.click('#decrypt-button');
    await view.waitForSelector('#decrypted-output:not(.d-none) pre', { timeout: 30000 });
    const text = await view.textContent('#decrypted-output pre');
    await view.close();
    return equal(text, 'top secret text');
  });
  await other.close();
}

// Expected: the sandbox probe's blocked request to the manager, the 404 after the last view
// of the burn-after-reading share, the 409 of the deliberate stale edit, and the 403 of the
// read-only refusal.
const expected = (line) => /manage-api\.php|CORS|Access-Control|Cross-Origin|access control|ERR_FAILED/i.test(line)
  || /^burn: .*404/.test(line)
  || /^manager: .*409/.test(line)
  || /^site-reader: .*403/.test(line);
const unexpected = consoleErrors.filter((line) => !expected(line));
console.log('== console errors');
if (unexpected.length) {
  unexpected.forEach((line) => console.log(`  FAIL console: ${line.slice(0, 300)}`));
  failures += unexpected.length;
} else {
  console.log(`  ok   no unexpected console errors (${consoleErrors.length} expected ones from the sandbox probe)`);
}
writeFileSync('/work/console.log', consoleErrors.join('\n'));
console.log(failures ? `FAILURES: ${failures}` : 'ALL PASSED');
