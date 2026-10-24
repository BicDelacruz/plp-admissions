// Run: node tests/ui_dialog_check.mjs   (needs playwright + chromium; no PHP or DB)
// Checks ui.alert / ui.confirm / ui.prompt / data-confirm on tests/ui_dialog_check.html.
import { chromium } from 'playwright';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
const url = 'file://' + path.join(path.dirname(fileURLToPath(import.meta.url)), 'ui_dialog_check.html');
let fails = 0;
const check = (ok, msg) => { if (!ok) { fails++; console.error('FAIL: ' + msg); } };
const browser = await chromium.launch();
const page = await browser.newPage();
const errs = []; page.on('pageerror', (e) => errs.push(e.message));
page.on('dialog', (d) => { fails++; console.error('FAIL: native dialog ' + d.message()); d.dismiss(); });
await page.goto(url);
const dlg = '[data-ui-dialog]';
const count = () => page.locator(dlg).count();

// alert: OK, Esc, backdrop
for (const how of ['ok', 'esc', 'backdrop']) {
  const p = page.evaluate(() => ui.alert('Saved\nline2').then(() => 'done'));
  await page.waitForSelector(dlg);
  check(await page.locator(dlg + ' .modal-title').innerText() === 'Notice', 'alert title');
  if (how === 'ok') await page.click('[data-ui-ok]');
  else if (how === 'esc') await page.keyboard.press('Escape');
  else await page.mouse.click(5, 5);
  check(await p === 'done', 'alert resolves via ' + how);
  check(await count() === 0, 'alert removed via ' + how);
}
// confirm: yes / no / esc / danger class
let p = page.evaluate(() => ui.confirm('Sure?', { danger: true, ok: 'Delete' }));
await page.waitForSelector(dlg);
check(await page.locator('[data-ui-ok]').evaluate((e) => e.classList.contains('btn-danger')), 'danger class');
check(await page.locator('[data-ui-ok]').innerText() === 'Delete', 'ok label');
await page.click('[data-ui-ok]'); check(await p === true, 'confirm yes');
p = page.evaluate(() => ui.confirm('Sure?')); await page.waitForSelector(dlg);
check(await page.locator('[data-ui-ok]').evaluate((e) => e.classList.contains('btn-primary')), 'primary class');
await page.click('[data-ui-cancel]'); check(await p === false, 'confirm cancel');
p = page.evaluate(() => ui.confirm('Sure?')); await page.waitForSelector(dlg);
await page.keyboard.press('Escape'); check(await p === false, 'confirm esc');
p = page.evaluate(() => ui.confirm('Sure?')); await page.waitForSelector(dlg);
await page.keyboard.press('Enter'); check(await p === true, 'confirm enter');
// text is not HTML
p = page.evaluate(() => ui.alert('<b id="x">hi</b>')); await page.waitForSelector(dlg);
check(await page.locator('#x').count() === 0, 'message is text');
await page.keyboard.press('Escape'); await p;
// prompt
p = page.evaluate(() => ui.prompt('Reason?', { value: 'abc' })); await page.waitForSelector(dlg);
check(await page.evaluate(() => document.activeElement.tagName) === 'INPUT', 'prompt focuses input');
await page.keyboard.type('def'); await page.keyboard.press('Enter');
check(await p === 'abcdef' || await p === 'def', 'prompt text (got ' + p + ')');
p = page.evaluate(() => ui.prompt('Reason?')); await page.waitForSelector(dlg);
await page.keyboard.press('Escape'); check(await p === null, 'prompt cancel is null');
// queue: two at once, shown one after the other, in order
p = page.evaluate(async () => { const a = ui.confirm('first'), b = ui.confirm('second'); return [await a, await b]; });
await page.waitForSelector(dlg);
check(await page.locator(dlg + ' p').innerText() === 'first', 'queue first');
await page.click('[data-ui-ok]');
await page.waitForFunction(() => document.querySelector('[data-ui-dialog] p')?.textContent === 'second');
await page.click('[data-ui-cancel]');
check(JSON.stringify(await p) === '[true,false]', 'queue results');
// over an open modal: on top, Esc closes only the dialog
await page.click('#open-behind');
p = page.evaluate(() => ui.confirm('Over modal?')); await page.waitForSelector(dlg);
check(await page.evaluate(() => { const r = document.querySelector('[data-ui-dialog] [data-ui-ok]').getBoundingClientRect();
  return document.elementFromPoint(r.x + 5, r.y + 5).closest('[data-ui-dialog]') !== null; }), 'dialog on top of modal');
await page.keyboard.press('Escape'); await p;
check(await page.locator('#behind').evaluate((e) => e.style.display) === 'flex', 'modal behind stays open after Esc');
await page.evaluate(() => (document.getElementById('behind').style.display = 'none'));
// focus returns, tab trap
await page.focus('#btn');
p = page.evaluate(() => ui.confirm('x')); await page.waitForSelector(dlg);
for (let i = 0; i < 5; i++) await page.keyboard.press('Tab');
check(await page.evaluate(() => !!document.activeElement.closest('[data-ui-dialog]')), 'tab stays in dialog');
await page.keyboard.press('Escape'); await p;
check(await page.evaluate(() => document.activeElement.id) === 'btn', 'focus returns');
// data-confirm button: cancel does not run onclick, ok runs it once
await page.click('#btn'); await page.waitForSelector(dlg);
await page.evaluate(() => document.getElementById('btn').click()); // second click while open is ignored
check(await count() === 1, 'double click opens one dialog');
await page.click('[data-ui-cancel]');
check(await page.evaluate(() => window.ran || 0) === 0, 'cancel: onclick not run');
await page.click('#btn'); await page.waitForSelector(dlg);
check(await page.locator('[data-ui-ok]').innerText() === 'Run', 'data-confirm-ok');
await page.click('[data-ui-ok]');
check(await page.evaluate(() => window.ran) === 1, 'ok: onclick ran once');
// data-confirm link
await page.click('#link'); await page.waitForSelector(dlg); await page.click('[data-ui-cancel]');
check(!page.url().endsWith('#linked'), 'link cancel stays');
await page.click('#link'); await page.waitForSelector(dlg); await page.click('[data-ui-ok]');
check(page.url().endsWith('#linked'), 'link ok follows');
// data-confirm form
await page.click('#fsubmit'); await page.waitForSelector(dlg); await page.click('[data-ui-cancel]');
check((await page.evaluate(() => window.submitted || 0)) === 0, 'form cancel: not submitted');
await page.click('#fsubmit'); await page.waitForSelector(dlg); await page.click('[data-ui-ok]');
check(await page.evaluate(() => window.submitted) === 1, 'form ok: submitted once');
await page.fill('#form input', ''); await page.click('#fsubmit'); await page.waitForTimeout(200);
check(await count() === 0 && await page.evaluate(() => window.submitted) === 1, 'invalid form: validation blocks first, no dialog');
// bulk delete button (confirmBulkDelete): cancel keeps ids empty, ok fills ids and submits once
await page.click('#bulk-btn'); await page.waitForSelector(dlg);
check(await page.locator(dlg + ' p').innerText() === 'Remove 2 slot(s)?', 'bulk message');
await page.click('[data-ui-cancel]');
check(await page.inputValue('#bulk-ids') === '' && !(await page.evaluate(() => window.bulkSent)), 'bulk cancel');
await page.click('#bulk-btn'); await page.waitForSelector(dlg); await page.click('[data-ui-ok]');
check(await page.inputValue('#bulk-ids') === '7,9' && await page.evaluate(() => window.bulkSent) === 1, 'bulk ok: ids set, sent once');
// eval form (apEvalConfirm): empty value alerts, pass asks, ok submits once
await page.click('#ev-btn'); await page.waitForSelector(dlg);
check(await page.locator(dlg + ' p').innerText() === 'Please choose Pass or Decline.', 'eval empty alert'); await page.click('[data-ui-ok]');
await page.evaluate(() => (document.getElementById('ev-in').value = 'reject'));
await page.click('#ev-btn'); await page.waitForSelector(dlg);
check(await page.locator('[data-ui-ok]').evaluate((e) => e.classList.contains('btn-danger')), 'eval reject is danger');
await page.click('[data-ui-cancel]'); check(await page.evaluate(() => window.__evSent) === 0, 'eval cancel: not sent');
await page.click('#ev-btn'); await page.waitForSelector(dlg); await page.click('[data-ui-ok]');
await page.waitForTimeout(100); check(await page.evaluate(() => window.__evSent) === 1, 'eval ok: sent once');
// look: dark + mobile screenshots
for (const [t, w] of [['light', 1000], ['dark', 375]]) {
  await page.setViewportSize({ width: w, height: 700 });
  await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), t);
  p = page.evaluate(() => ui.confirm('Close admissions? Applicants can no longer submit.', { danger: true, ok: 'Close' }));
  await page.waitForSelector(dlg); await page.waitForTimeout(300);
  await page.screenshot({ path: `/tmp/ui_dialog_${t}.png` });
  await page.keyboard.press('Escape'); await p;
}
check(errs.length === 0, 'page errors: ' + errs.join(' | '));

// Live-exam prompt from modules/exam/staff_manage.php, injected before app.js like the real page.
const src = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '../modules/exam/staff_manage.php'), 'utf8');
const a = src.indexOf('// \u2500\u2500 Live-exam prompt'), b = src.indexOf('})();', a) + 5;
const snippet = src.slice(a, b).replace("<?= !empty($examLive) ? 'true' : 'false' ?>", 'true');
const p2 = await browser.newPage();
await p2.addInitScript(() => { HTMLFormElement.prototype.submit = function () { window.sub = (window.sub || 0) + 1; }; });
await p2.addInitScript(snippet);
await p2.goto(url);
await p2.evaluate(() => {
  document.body.insertAdjacentHTML('beforeend',
    '<form id="dq" data-confirm="Remove this question?" data-confirm-danger onsubmit="window.sent=(window.sent||0)+1;return false">' +
    '<input type="hidden" name="action" value="delete_question"><button id="dqb">x</button></form>' +
    '<form id="de"><input type="hidden" name="action" value="delete_exam"></form>');
});
const txt = () => p2.locator(dlg + ' p').innerText();
await p2.click('#dqb'); await p2.waitForSelector(dlg);
check((await txt()).startsWith('Students are taking the exam'), 'live prompt first');
await p2.click('[data-ui-ok]');
await p2.waitForFunction(() => document.querySelector('[data-ui-dialog] p')?.textContent === 'Remove this question?');
check(!(await p2.evaluate(() => window.sent)), 'not sent before second OK');
await p2.click('[data-ui-ok]'); await p2.waitForTimeout(150);
check(await p2.evaluate(() => window.sent) === 1, 'sent exactly once after both OKs');
check(await p2.evaluate(() => !!document.querySelector('#dq input[name=confirm_live]')), 'confirm_live added');
// programmatic form.submit() (delete exam button): live prompt, cancel then ok
await p2.evaluate(() => document.getElementById('de').submit()); await p2.waitForSelector(dlg);
await p2.click('[data-ui-cancel]'); await p2.waitForTimeout(100);
check(!(await p2.evaluate(() => window.sub)), 'form.submit() cancel: not submitted');
await p2.evaluate(() => document.getElementById('de').submit()); await p2.waitForSelector(dlg); await p2.click('[data-ui-ok]'); await p2.waitForTimeout(100);
check(await p2.evaluate(() => window.sub) === 1, 'form.submit() ok: submitted once');
// fetch wrapper: risky action asks first; cancel returns 409 and never reaches the network
await p2.evaluate(() => { window.__calls = 0; });
const r = p2.evaluate(async () => { const fd = new FormData(); fd.append('action', 'delete_section'); const res = await fetch('data:,x', { method: 'POST', body: fd }).catch(() => null); return res ? res.status : 'net'; });
await p2.waitForSelector(dlg); await p2.click('[data-ui-cancel]');
check(await r === 409, 'fetch cancel gives 409');
await p2.close();
await browser.close();
if (fails) { console.error(fails + ' failed'); process.exit(1); }
console.log('OK');
