import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import path from 'node:path';

const root = process.env.WP_ROOT;

function fixture(action) {
  const output = execFileSync('wp', [
    'eval-file', path.resolve('tests/booking-entry-browser-fixture.php'), '--path=' + root
  ], {encoding: 'utf8', env: {...process.env, WPCB_ENTRY_ACTION: action}}).trim();
  return JSON.parse(output.split(/\r?\n/).filter(Boolean).pop());
}

function enableFixtureGroups() {
  // Only the two disposable free types created by fixture('setup') are changed.
  execFileSync('wp', ['eval', `
    global $wpdb;
    $state = get_option('wpcb_entry_e2e_fixture', []);
    if (count($state['types'] ?? []) !== 3) throw new RuntimeException('Missing entry fixture');
    foreach (array_slice($state['types'], 0, 2) as $id) {
        if ($wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['capacity' => 4], ['id' => (int)$id]) !== 1) {
            throw new RuntimeException('Unable to enable fixture group capacity');
        }
    }
  `, '--path=' + root], {encoding: 'utf8'});
}

async function releaseObsolete(page, route, payload, aborts, failures) {
  if (aborts) {
    // Prove the browser actually cancelled the old request, rather than merely
    // assuming cancellation because an AbortController was constructed.
    try { await route.fulfill(payload); }
    catch { /* Chromium may already have removed the cancelled interception. */ }
    await expect.poll(() => failures.has(route.request())).toBe(true);
    return;
  }
  const responsePromise = page.waitForResponse(response => response.request() === route.request());
  await route.fulfill(payload);
  const response = await responsePromise;
  expect(await response.finished()).toBeNull();
  // Drain the delivered response's promise/DOM work before negative assertions.
  await page.evaluate(() => new Promise(resolve => {
    requestAnimationFrame(() => requestAnimationFrame(resolve));
  }));
}

for (const aborts of [true, false]) {
  test(`packaged party-size race preserves both forms ${aborts ? 'with' : 'without'} AbortController`, async ({page}) => {
    test.setTimeout(120000);
    if (!aborts) {
      await page.addInitScript(() => { window.AbortController = undefined; });
    }
    const held = new Map();
    const failures = new Set();
    const errors = [];
    page.on('requestfailed', request => failures.add(request));
    page.on('pageerror', error => errors.push(error.message));
    try {
      const setup = fixture('setup');
      enableFixtureGroups();
      const [typeA, typeB] = setup.types.map(String);
      const token = (type, party) => `synthetic-party-${type}-${party}`;
      await page.route('**/admin-ajax.php', async route => {
        const params = new URLSearchParams(route.request().postData() || '');
        if (params.get('action') !== 'wpcb_get_slots') return route.continue();
        const type = params.get('type_id');
        const party = params.get('party_size');
        if (type === typeA && ['2', '4'].includes(party)) {
          if (!held.has(party)) held.set(party, []);
          held.get(party).push(route);
          return;
        }
        return route.fulfill({json: {success: true, data: {slots: [
          {value: token(type, party), label: `Synthetic party ${party}`}
        ]}}});
      });
      await page.goto(setup.page);
      const form = page.locator('.wpcb-booking-form-wrap [data-wpcb-booking-form]');
      const modal = page.locator('[data-wpcb-modal]');
      const second = modal.locator('[data-wpcb-booking-form]');
      const party = form.locator('[data-wpcb-party-size]');
      const slots = form.locator('[data-wpcb-slot-select]');
      const submit = form.locator('button[type="submit"]');
      const choose = async (target, type, size) => {
        const select = target.locator('[data-wpcb-slot-select]');
        await expect(select.locator(`option[value="${token(type, size)}"]`)).toHaveCount(1);
        await select.selectOption(token(type, size));
        await expect(target.locator('button[type="submit"]')).toBeEnabled();
      };
      await form.locator('[data-wpcb-type-select]').selectOption(typeA);
      await expect(party).toBeVisible();
      await expect(party).toHaveAttribute('max', '4');
      await choose(form, typeA, '1');

      // Both real rendered forms now have a selection, not just an idle modal.
      await page.locator('[data-wpcb-open-toolbar-modal]').click();
      await expect(modal).toBeVisible();
      await second.locator('[data-wpcb-type-select]').selectOption(typeB);
      await choose(second, typeB, '1');
      await modal.locator('[data-wpcb-close-modal]').click();
      await expect(modal).toBeHidden();
      await expect(slots).toHaveValue(token(typeA, '1'));
      await expect(submit).toBeEnabled();
      const before = fixture('counts');

      // A slower two-person response must not replace the three-person slot.
      await party.fill('2');
      await expect.poll(() => held.get('2')?.length || 0).toBeGreaterThan(0);
      await expect(slots).toHaveValue('');
      await expect(slots).toBeDisabled();
      await expect(submit).toBeDisabled();
      await party.fill('3');
      await choose(form, typeA, '3');
      for (const route of held.get('2')) {
        await releaseObsolete(page, route, {json: {success: true, data: {slots: [
          {value: token(typeA, '2'), label: 'Obsolete two-person slot'}
        ]}}}, aborts, failures);
      }
      await expect(slots).toHaveValue(token(typeA, '3'));
      await expect(slots.locator(`option[value="${token(typeA, '2')}"]`)).toHaveCount(0);
      await expect(submit).toBeEnabled();

      // An obsolete failure must not clear a newer successful selection either.
      await party.fill('4');
      await expect.poll(() => held.get('4')?.length || 0).toBeGreaterThan(0);
      await expect(slots).toHaveValue('');
      await expect(submit).toBeDisabled();
      await party.fill('1');
      await choose(form, typeA, '1');
      for (const route of held.get('4')) {
        await releaseObsolete(page, route, {status: 429, json: {
          success: false, data: {message: 'Obsolete four-person failure'}
        }}, aborts, failures);
      }
      await expect(slots).toHaveValue(token(typeA, '1'));
      await expect(submit).toBeEnabled();
      await expect(form.locator('[data-wpcb-slot-status]')).toHaveText('');
      await expect(form.locator('[data-wpcb-slot-retry]')).toBeHidden();
      await expect(second.locator('[data-wpcb-type-select]')).toHaveValue(typeB);
      await expect(second.locator('[data-wpcb-party-size]')).toHaveValue('1');
      await expect(second.locator('[data-wpcb-slot-select]')).toHaveValue(token(typeB, '1'));
      await expect(second.locator('button[type="submit"]')).toBeEnabled();
      await expect(second.locator('[data-wpcb-slot-retry]')).toBeHidden();
      expect(fixture('counts')).toEqual(before);
      expect(errors).toEqual([]);
    } finally {
      await page.unroute('**/admin-ajax.php');
      fixture('cleanup');
    }
  });
}
