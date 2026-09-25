import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
const base = process.env.WPCB_E2E_BASE_URL || 'http://127.0.0.1:8080';
const root = process.env.WP_ROOT;
function fixture(action) {
  const out = execFileSync('wp', ['eval-file', path.resolve('tests/booking-entry-browser-fixture.php'), '--path=' + root], {encoding:'utf8',env:{...process.env,WPCB_ENTRY_ACTION:action}}).trim();
  return JSON.parse(out.split(/\r?\n/).filter(Boolean).pop());
}

test('packaged payment preflight keeps free forms usable and rejects stale paid submissions without effects', async ({page}) => {
  test.setTimeout(120000);
  try {
    const setup = fixture('setup');
    await page.goto(setup.page);
    for (const form of await page.locator('[data-wpcb-booking-form]').all()) {
      await expect(form.locator(`option[value="${setup.types[2]}"]`)).toBeDisabled();
      await expect(form.locator(`option[value="${setup.types[0]}"]`)).toBeEnabled();
    }
    await expect(page.locator('[data-wpcb-payment-unavailable]')).toHaveCount(2);
    // Fetch a stale form while local synthetic credentials are present. No Stripe request is made.
    fixture('ready');
    await page.goto(base + '/?wpcb_action=book');
    const form = page.locator('[data-wpcb-booking-form]');
    await form.locator('[data-wpcb-type-select]').selectOption(String(setup.types[2]));
    const slots = form.locator('[data-wpcb-slot-select]');
    await expect.poll(() => slots.locator('option').count()).toBeGreaterThan(1);
    await slots.selectOption({index:1});
    await form.locator('[name="subject"]').fill('Entry acceptance');
    await form.locator('[name="gender"]').selectOption('Herr');
    await form.locator('[name="first_name"]').fill('Synthetic');
    await form.locator('[name="last_name"]').fill('Entry');
    await form.locator('[name="email"]').fill('entry-e2e@example.invalid');
    await form.locator('[name="privacy"]').check();
    fixture('disabled');
    const before = fixture('counts');
    const [response] = await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
    expect(response.status()).toBe(409);
    await expect(page.locator('[data-wpcb-rebook]')).toBeVisible();
    expect(fixture('counts')).toEqual(before);
    const href = await page.locator('[data-wpcb-rebook]').getAttribute('href');
    expect(new URL(href).search).toBe('?wpcb_action=book');
    await page.locator('[data-wpcb-rebook]').click();
    await expect(page.locator('[data-wpcb-booking-form]')).toBeVisible();
    await expect(page.locator('[name="email"]')).toHaveValue('');
  } finally { fixture('cleanup'); }
});

test('packaged expired single and series links offer fresh booking without modifying data or consuming DOI', async ({page}) => {
  test.setTimeout(120000);
  try {
    fixture('setup');
    for (const action of ['make_single', 'make_series']) {
      const made = fixture(action);
      await page.goto(made.url);
      await expect(page.getByRole('button', {name:'E-Mail best\u00e4tigen'})).toBeVisible();
      // Expire after GET: the domain rejects POST, then the new read-only screen explains why.
      fixture('expire');
      const before = fixture('counts');
      await Promise.all([page.waitForNavigation(), page.getByRole('button',{name:'E-Mail best\u00e4tigen'}).click()]);
      await expect(page.getByRole('heading',{name:'Reservierung abgelaufen'})).toBeVisible();
      await expect(page.locator('form:has(input[name="wpcb_token"])')).toHaveCount(0);
      expect(fixture('counts')).toEqual(before);
      const response = await page.goto(made.url);
      expect(response.status()).toBe(410);
      expect(fixture('counts')).toEqual(before);
      const href = await page.locator('[data-wpcb-rebook]').getAttribute('href');
      expect(new URL(href).search).toBe('?wpcb_action=book');
      expect(href).not.toContain(new URL(made.url).searchParams.get('wpcb_token'));
    }
  } finally { fixture('cleanup'); }
});

test('packaged form ignores obsolete requests, separates errors from empty availability and keeps forms independent', async ({page}) => {
  test.setTimeout(120000);
  try {
    const setup = fixture('setup');
    await page.goto(setup.page);
    let pending;
    await page.route('**/admin-ajax.php', async route => {
      const params = new URLSearchParams(route.request().postData());
      if (params.get('action') !== 'wpcb_get_slots') return route.continue();
      if (params.get('type_id') === String(setup.types[0])) { pending=route; return; }
      return route.fulfill({json:{success:true,data:{slots:[{value:'synthetic-B-token',label:'Synthetic B'}]}}});
    });
    const form = page.locator('.wpcb-booking-form-wrap [data-wpcb-booking-form]');
    await form.locator('[data-wpcb-type-select]').selectOption(String(setup.types[0]));
    await expect.poll(() => Boolean(pending)).toBe(true);
    await form.locator('[data-wpcb-type-select]').selectOption(String(setup.types[1]));
    await expect(form.locator('option[value="synthetic-B-token"]')).toHaveCount(1);
    try { await pending.fulfill({json:{success:true,data:{slots:[{value:'obsolete-A',label:'Obsolete'}]}}}); } catch { /* AbortController may already have cancelled this request. */ }
    await expect(form.locator('option[value="obsolete-A"]')).toHaveCount(0);
    await expect(form.locator('[data-wpcb-slot-select]')).toHaveValue('');
    await expect(form.locator('button[type="submit"]')).toBeDisabled();
    await page.unroute('**/admin-ajax.php');
    await page.route('**/admin-ajax.php', route => route.fulfill({status:429,json:{success:false,data:{message:'Synthetic rate limit'}}}));
    await form.locator('[data-wpcb-type-select]').selectOption(String(setup.types[0]));
    await expect(form.locator('[data-wpcb-slot-status]')).toHaveText('Synthetic rate limit');
    await expect(form.locator('[data-wpcb-slot-retry]')).toBeVisible();
    await page.unroute('**/admin-ajax.php');
    await page.route('**/admin-ajax.php', route => route.fulfill({json:{success:true,data:{slots:[]}}}));
    await form.locator('[data-wpcb-slot-retry]').click();
    await expect(form.locator('[data-wpcb-slot-retry]')).toBeHidden();
    await expect(form.locator('[data-wpcb-slot-status]')).not.toHaveText('Synthetic rate limit');
    await expect(page.locator('[data-wpcb-modal] [data-wpcb-slot-select] option')).toHaveCount(1);
  } finally { fixture('cleanup'); }
});
