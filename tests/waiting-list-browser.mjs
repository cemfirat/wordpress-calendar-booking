import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const baseUrl = process.env.WPCB_E2E_BASE_URL || 'http://127.0.0.1:8080';
const wpRoot = process.env.WP_ROOT;
const fixtureFile = path.resolve('tests/browser-release-fixture.php');

if (!wpRoot) {
    throw new Error('WP_ROOT is required.');
}

function fixture(action) {
    const output = execFileSync(
        'wp',
        ['eval-file', fixtureFile, '--path=' + wpRoot],
        {
            cwd: process.cwd(),
            env: { ...process.env, WPCB_E2E_ACTION: action },
            encoding: 'utf8',
        }
    ).trim();
    return output.split(/\r?\n/).filter(Boolean).pop() || '';
}

function fixtureJson(action) {
    const value = fixture(action);
    return value ? JSON.parse(value) : {};
}

function mailbox() {
    const value = fixtureJson('mailbox');
    return Array.isArray(value) ? value : [];
}

function confirmUrl() {
    for (const mail of mailbox().toReversed()) {
        const body = String(mail.message || '')
            .replaceAll('&amp;', '&')
            .replaceAll('&#038;', '&')
            .replaceAll('&#38;', '&');
        for (const raw of body.match(/https?:\/\/[^\s<>"']+/g) || []) {
            try {
                const url = new URL(raw.replace(/[),.;]+$/, ''));
                if (url.searchParams.get('wpcb_action') === 'confirm'
                    && url.searchParams.get('wpcb_token')) {
                    return url.toString();
                }
            } catch {
                // Ignore unrelated fragments.
            }
        }
    }
    return '';
}

test('packaged paid waiting-list offer reaches DOI and final confirmation without duplicate Checkout', async ({ page }) => {
    test.setTimeout(120000);
    const setup = fixtureJson('waitlist_setup');

    try {
        await page.goto(setup.offer_url, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: /booking slot is available/i })).toBeVisible();

        const form = page.locator('form');
        const action = await form.getAttribute('action');
        const fields = await form.evaluate((element) => Object.fromEntries(new FormData(element).entries()));
        expect(fields.privacy).toBe('1');
        expect(fields.gender).toBe('Divers');

        const invalid = await page.request.post(action, {
            form: { ...fields, gender: 'stale-option' },
            maxRedirects: 0,
        });
        expect(invalid.status()).toBe(400);
        const recovery = await invalid.text();
        expect(recovery).toContain('Eine Auswahl ist nicht mehr gültig');
        expect(recovery).toContain('value="waitlist-browser@example.com"');

        const acceptance = await page.request.post(action, {
            form: fields,
            maxRedirects: 0,
        });
        expect(acceptance.status()).toBe(303);
        expect(acceptance.headers().location || '').toMatch(/^https:\/\/checkout\.stripe\.com\//);

        await expect.poll(
            () => fixtureJson('waitlist_status').booking?.status || '',
            { timeout: 10000, intervals: [100, 200, 500] }
        ).toBe('reserved_unconfirmed');
        const pending = fixtureJson('waitlist_status');
        expect(pending.payment_status).toBe('pending');
        expect(pending.provider_reference).toBe('cs_waitlist_browser');

        await expect.poll(
            () => confirmUrl(),
            { timeout: 10000, intervals: [100, 200, 500] }
        ).not.toBe('');
        const doiUrl = confirmUrl();

        // DOI is visible/read-only by GET, but payment still gates the mutation.
        await page.goto(doiUrl, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: /terminbuchung bestätigen/i })).toBeVisible();
        await page.locator('form.wpcb-public-action-form button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        expect(fixtureJson('waitlist_status').booking?.status).toBe('reserved_unconfirmed');

        fixture('waitlist_mark_paid');
        expect(fixtureJson('waitlist_status').payment_status).toBe('paid');

        // The same one-time DOI token remains valid after the rejected unpaid
        // attempt and can now complete the booking exactly once.
        await page.goto(doiUrl, { waitUntil: 'domcontentloaded' });
        await page.locator('form.wpcb-public-action-form button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');

        await expect.poll(
            () => fixtureJson('waitlist_status').booking?.status || '',
            { timeout: 10000, intervals: [100, 200, 500] }
        ).toBe('confirmed');

        await page.goto(doiUrl, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('form.wpcb-public-action-form')).toHaveCount(0);
    } finally {
        fixture('cleanup');
    }
});
