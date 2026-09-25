import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
const base=process.env.WPCB_E2E_BASE_URL || 'http://127.0.0.1:8080';
function state(action='state') {
  const out=execFileSync('wp',['eval-file',path.resolve('tests/demo-browser-fixture.php'),'--path='+process.env.WP_ROOT],{encoding:'utf8',env:{...process.env,WPCB_DEMO_TEST:action}}).trim();
  return JSON.parse(out.split(/\r?\n/).filter(Boolean).pop());
}
test('packaged optional demo creates ten, stays isolated and removes without touching real data',async({page,browser})=>{
  test.setTimeout(120000);
  state('remove');
  const before=state();
  try {
    await page.goto(base+'/wp-login.php');
    await page.locator('#user_login').fill(process.env.WPCB_E2E_ADMIN_USER || 'admin');
    await page.locator('#user_pass').fill(process.env.WPCB_E2E_ADMIN_PASSWORD || 'integration-only');
    await Promise.all([page.waitForURL(/\/wp-admin\//),page.locator('#wp-submit').click()]);
    const url=base+'/wp-admin/admin.php?page=wpcb_demo_calendar';
    await page.goto(url);
    await expect(page.locator('[data-wpcb-demo-count="0"]')).toBeVisible();
    let form=page.locator('form:has(input[name="demo_operation"])');
    const nonce=await form.locator('input[name="_wpnonce"]').inputValue();
    const post=base+'/wp-admin/admin-post.php';
    let response=await page.request.get(post+'?action=wpcb_demo_calendar');
    expect(response.status()).toBe(405);
    response=await page.request.post(post,{form:{action:'wpcb_demo_calendar',demo_operation:'create',confirm_demo:'1'}});
    expect(response.status()).toBe(403);
    response=await page.request.post(post,{form:{action:'wpcb_demo_calendar',demo_operation:'create',_wpnonce:nonce}});
    expect(response.status()).toBe(400);
    expect(state()).toEqual(before);
    await form.locator('[name="confirm_demo"]').check();
    await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'10 Beispieleinträge erzeugen'}).click()]);
    await expect(page.locator('[data-wpcb-demo-entry]')).toHaveCount(10);
    await expect(page.locator('[data-wpcb-demo-month]').first().locator('thead th')).toHaveCount(7);
    expect(await page.locator('a[href="#demo-10"]').count()).toBeGreaterThan(1);
    const created=state(); expect(created.count).toBe(10);expect(created.real).toEqual(before.real);
    // Replaying a valid old create request must not append another ten entries.
    response=await page.request.post(post,{form:{action:'wpcb_demo_calendar',demo_operation:'create',_wpnonce:nonce,confirm_demo:'1'}});
    expect(response.ok()).toBe(true);
    expect(state()).toEqual(created);
    await page.reload();await expect(page.locator('[data-wpcb-demo-entry]')).toHaveCount(10);
    const guest=await browser.newContext();
    try {
      const anonymous=await guest.newPage();await anonymous.goto(url);
      await expect(anonymous.locator('[data-wpcb-demo-entry]')).toHaveCount(0);
      response=await guest.request.post(post,{form:{action:'wpcb_demo_calendar',demo_operation:'remove',_wpnonce:nonce,confirm_demo:'1'}});
      expect(response.ok()).toBe(false);expect(state()).toEqual(created);
    } finally {await guest.close();}
    form=page.locator('form:has(input[name="demo_operation"])');
    await form.locator('[name="confirm_demo"]').check();
    await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Beispieleinträge entfernen'}).click()]);
    await expect(page.locator('[data-wpcb-demo-entry]')).toHaveCount(0);
    expect(state()).toEqual(before);
    form=page.locator('form:has(input[name="demo_operation"])');
    await form.locator('[name="confirm_demo"]').check();
    await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'10 Beispieleinträge erzeugen'}).click()]);
    await expect(page.locator('[data-wpcb-demo-entry]')).toHaveCount(10);
    expect(state().real).toEqual(before.real);
  } finally {state('remove');}
});
