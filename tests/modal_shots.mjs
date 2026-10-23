// Run: npx playwright test is NOT needed. Usage:
//   npm i playwright (once, anywhere) then
//   BASE=http://localhost:8000/public node tests/modal_shots.mjs <outDir>
// Logs in per role, opens every modal on each page, saves PNGs: desktop+mobile, light+dark.
// Run once BEFORE the change (outDir=before) and once after (outDir=after), then compare.
// ponytail: opens modals directly, not through their real triggers. Trigger paths are tested by hand.
import { chromium } from 'playwright';
import fs from 'fs';
const BASE = process.env.BASE || 'http://localhost:8000/public';
const OUT = process.argv[2] || 'shots';
const roles = {
  admin: { email: 'admin@plp.edu.ph', pw: 'Admin@123', pages: ['/admin/users', '/admin/courses', '/admin/school-year', '/admin/audit-log'] },
  sso:   { email: 'sso@plp.edu.ph', pw: 'SSO@123', pages: ['/staff/exam', '/staff/exam/slots', '/staff/results', '/staff/applicants', '/staff/interviews/queue', '/staff/interviews/setup'] },
  student: { email: process.env.STUDENT_EMAIL, pw: process.env.STUDENT_PW, pages: ['/student/documents', '/student/exam'] },
};
const sizes = { desktop: { width: 1366, height: 800 }, mobile: { width: 375, height: 760 } };
const open = (id) => { const el = document.getElementById(id);
  if (el.querySelector('.modal-dialog') && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
  else el.style.display = 'flex'; };
const close = (id) => { const el = document.getElementById(id);
  if (el.querySelector('.modal-dialog') && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).hide();
  else el.style.display = 'none'; };
const browser = await chromium.launch();
for (const [role, r] of Object.entries(roles)) {
  if (!r.email) { console.log('skip', role); continue; }
  for (const [sn, vp] of Object.entries(sizes)) for (const theme of ['light', 'dark']) {
    const ctx = await browser.newContext({ viewport: vp });
    const page = await ctx.newPage();
    await page.goto(BASE + '/login');
    await page.fill('#email', r.email); await page.fill('#password', r.pw);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    for (const p of r.pages) {
      await page.goto(BASE + p);
      await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
      const ids = await page.evaluate(() => [...document.querySelectorAll('.modal-backdrop[id], .modal[id]')].map((e) => e.id));
      for (const id of ids) {
        await page.evaluate(open, id); await page.waitForTimeout(400);
        const dir = `${OUT}/${role}/${sn}-${theme}`; fs.mkdirSync(dir, { recursive: true });
        await page.screenshot({ path: `${dir}/${p.replace(/\W+/g, '_')}__${id}.png` });
        await page.evaluate(close, id); await page.waitForTimeout(300);
      }
    }
    await ctx.close();
  }
}
await browser.close();
