import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const baseUrl = process.env.WPCB_E2E_BASE_URL || 'http://127.0.0.1:8080';
const wpRoot = process.env.WP_ROOT;
const adminUser = process.env.WPCB_E2E_ADMIN_USER || 'admin';
const adminPassword = process.env.WPCB_E2E_ADMIN_PASSWORD || 'integration-only';
const testEmail = 'browser-e2e@example.com';
const testFirstName = 'Browser';
const testLastName = 'Tester';
const fixtureFile = path.resolve('tests/browser-release-fixture.php');
const artifactDir = path.resolve('tests/browser-artifacts');

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

function bookingStatus() {
    return fixtureJson('status');
}

function mailbox() {
    const result = fixtureJson('mailbox');
    return Array.isArray(result) ? result : [];
}

function decodeMailHtml(value) {
    return String(value || '')
        .replaceAll('&amp;', '&')
        .replaceAll('&#038;', '&')
        .replaceAll('&#38;', '&');
}

function actionUrlFromMailbox(action) {
    const messages = mailbox();
    for (let i = messages.length - 1; i >= 0; i -= 1) {
        const body = decodeMailHtml(messages[i].message);
        const matches = body.match(/https?:\/\/[^\s<>"']+/g) || [];
        for (const raw of matches) {
            const candidate = raw.replace(/[),.;]+$/, '');
            try {
                const url = new URL(candidate);
                if (url.searchParams.get('wpcb_action') === action && url.searchParams.get('wpcb_token')) {
                    return url.toString();
                }
            } catch {
                // Ignore unrelated text fragments.
            }
        }
    }
    return '';
}

async function waitForActionMail(action) {
    await expect.poll(
        () => actionUrlFromMailbox(action),
        { timeout: 10000, intervals: [100, 200, 500] }
    ).not.toBe('');
    return actionUrlFromMailbox(action);
}

async function waitForBookingStatus(status) {
    await expect.poll(
        () => bookingStatus().status || '',
        { timeout: 10000, intervals: [100, 200, 500] }
    ).toBe(status);
    return bookingStatus();
}

function watchPluginErrors(page, errors) {
    page.on('console', (message) => {
        if (message.type() !== 'error') return;
        const location = message.location();
        if ((location.url || '').includes('/wp-content/plugins/wordpress-calendar-booking/')) {
            errors.push('console: ' + message.text());
        }
    });
    page.on('pageerror', (error) => {
        if (String(error.stack || error.message).includes('wordpress-calendar-booking')) {
            errors.push('pageerror: ' + error.message);
        }
    });
}

async function redactAndScreenshot(page, name) {
    if (!page || page.isClosed()) return;
    try {
        await page.evaluate(({ email, firstName, lastName }) => {
            document.querySelectorAll('input, textarea').forEach((element) => {
                if (element.type !== 'hidden') element.value = '';
            });
            document.querySelectorAll('td, p, div, span').forEach((element) => {
                const text = element.textContent || '';
                if (text.includes(email) || text.includes(firstName + ' ' + lastName)) {
                    element.textContent = '[redacted]';
                }
            });
        }, { email: testEmail, firstName: testFirstName, lastName: testLastName });
        mkdirSync(artifactDir, { recursive: true });
        await page.screenshot({ path: path.join(artifactDir, name), fullPage: true });
    } catch {
        // Failure diagnostics must never hide the original test error.
    }
}

test('canonical release ZIP passes the complete booking lifecycle in a browser', async ({ browser }) => {
    test.setTimeout(180000);
    fixture('setup');

    const pluginErrors = [];
    let lastPage = null;
    let adminContext;
    let visitorContext;

    try {
        adminContext = await browser.newContext();
        visitorContext = await browser.newContext();

        const adminPage = await adminContext.newPage();
        const visitorPage = await visitorContext.newPage();
        lastPage = visitorPage;
        watchPluginErrors(adminPage, pluginErrors);
        watchPluginErrors(visitorPage, pluginErrors);

        // Administrator creates a booking type through the real wp-admin UI.
        await adminPage.goto(baseUrl + '/wp-login.php', { waitUntil: 'domcontentloaded' });
        await adminPage.locator('#user_login').fill(adminUser);
        await adminPage.locator('#user_pass').fill(adminPassword);
        await Promise.all([
            adminPage.waitForURL(/\/wp-admin\//),
            adminPage.locator('#wp-submit').click(),
        ]);

        await adminPage.goto(baseUrl + '/wp-admin/admin.php?page=wpcb_types', { waitUntil: 'domcontentloaded' });
        const typeForm = adminPage.locator('form:has(input[name="wpcb_admin_action"][value="save_type"])');
        await typeForm.locator('input[name="name"]').fill('Browser E2E');
        await typeForm.locator('input[name="slug"]').fill('browser-e2e');
        await typeForm.locator('textarea[name="description"]').fill('Release acceptance booking type');
        await typeForm.locator('input[name="duration_minutes"]').fill('30');
        await typeForm.locator('input[name="buffer_after_minutes"]').fill('0');
        await Promise.all([
            adminPage.waitForNavigation(),
            typeForm.getByRole('button', { name: 'Speichern' }).click(),
        ]);
        await expect(adminPage.getByRole('cell', { name: 'Browser E2E' })).toBeVisible();

        // Administrator also creates availability for that booking type.
        await adminPage.goto(baseUrl + '/wp-admin/admin.php?page=wpcb_availability', { waitUntil: 'domcontentloaded' });
        const ruleForm = adminPage.locator('form:has(input[name="wpcb_admin_action"][value="save_rule"])');
        const rulesBefore = await adminPage.locator('table').first().locator('tbody tr').count();
        await ruleForm.locator('select[name="scope_type"]').selectOption('booking_type');
        await ruleForm.locator('select[name="scope_id"]').selectOption({ label: 'Browser E2E' });
        await ruleForm.locator('input[name="weekday"]').fill('1');
        await ruleForm.locator('input[name="start_time"]').fill('08:00');
        await ruleForm.locator('input[name="end_time"]').fill('18:00');
        await ruleForm.locator('input[name="slot_duration_minutes"]').fill('30');
        await ruleForm.locator('input[name="buffer_after_minutes"]').fill('0');
        await ruleForm.locator('input[name="min_notice_minutes"]').fill('0');
        await ruleForm.locator('input[name="max_days_in_advance"]').fill('30');
        await Promise.all([
            adminPage.waitForNavigation(),
            ruleForm.getByRole('button', { name: 'Speichern' }).click(),
        ]);
        await expect.poll(
            () => adminPage.locator('table').first().locator('tbody tr').count()
        ).toBeGreaterThan(rulesBefore);

        // Both public integration surfaces must render from the packaged plugin.
        const shortcodeUrl = baseUrl + '/?pagename=wpcb-e2e-shortcodes';
        const blockUrl = baseUrl + '/?pagename=wpcb-e2e-blocks';

        await visitorPage.goto(blockUrl, { waitUntil: 'networkidle' });
        await expect(visitorPage.locator('[data-wpcb-booking-calendar]')).toBeVisible();
        await expect(visitorPage.locator('[data-wpcb-booking-form]')).toHaveCount(2);

        await visitorPage.goto(shortcodeUrl, { waitUntil: 'networkidle' });
        await expect(visitorPage.locator('[data-wpcb-booking-calendar]')).toBeVisible();
        await expect(visitorPage.locator('.wpcb-booking-form-wrap [data-wpcb-booking-form]')).toBeVisible();

        // The calendar modal is keyboard-operable and restores focus to its trigger.
        const modalTrigger = visitorPage.locator('[data-wpcb-open-toolbar-modal]').first();
        await modalTrigger.focus();
        await visitorPage.keyboard.press('Enter');
        const modal = visitorPage.locator('[data-wpcb-modal]').first();
        await expect(modal).toBeVisible();
        await expect.poll(
            () => visitorPage.evaluate(() => document.activeElement?.hasAttribute('data-wpcb-modal-panel') || false)
        ).toBe(true);
        await visitorPage.keyboard.press('Escape');
        await expect(modal).toBeHidden();
        await expect.poll(() => modalTrigger.evaluate((element) => document.activeElement === element)).toBe(true);

        // Visitor creates a booking through the public shortcode form.
        const bookingForm = visitorPage.locator('.wpcb-booking-form-wrap [data-wpcb-booking-form]');
        await bookingForm.locator('[name="booking_type_id"]').selectOption({ label: 'Browser E2E' });
        const slotSelect = bookingForm.locator('[name="slot_token"]');
        await expect.poll(() => slotSelect.locator('option').count(), { timeout: 10000 }).toBeGreaterThan(1);
        const slotValues = await slotSelect.locator('option').evaluateAll((options) =>
            options.map((option) => option.value).filter(Boolean)
        );
        expect(slotValues.length).toBeGreaterThan(0);
        await slotSelect.selectOption(slotValues[0]);

        await bookingForm.locator('[name="subject"]').fill('Release acceptance');
        await bookingForm.locator('[name="gender"]').selectOption('Herr');
        await bookingForm.locator('[name="first_name"]').fill(testFirstName);
        await bookingForm.locator('[name="last_name"]').fill(testLastName);
        await bookingForm.locator('[name="email"]').fill(testEmail);
        await bookingForm.locator('[name="message"]').fill('Private browser E2E note');
        await bookingForm.locator('[name="privacy"]').check();

        const submitButton = bookingForm.getByRole('button', { name: 'Termin buchen' });
        await submitButton.focus();
        await Promise.all([
            visitorPage.waitForNavigation(),
            visitorPage.keyboard.press('Enter'),
        ]);
        await waitForBookingStatus('reserved_unconfirmed');

        // DOI GET is read-only; mutation happens only after keyboard-submitting the form.
        const confirmUrl = await waitForActionMail('confirm');
        await visitorPage.goto(confirmUrl, { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByRole('heading', { name: 'Terminbuchung bestätigen' })).toBeVisible();
        await waitForBookingStatus('reserved_unconfirmed');
        const confirmButton = visitorPage.getByRole('button', { name: 'E-Mail bestätigen' });
        await confirmButton.focus();
        await Promise.all([
            visitorPage.waitForNavigation(),
            visitorPage.keyboard.press('Enter'),
        ]);
        await waitForBookingStatus('pending_approval');

        // Administrator approves the booking in wp-admin.
        await adminPage.goto(baseUrl + '/wp-admin/admin.php?page=wpcb_bookings', { waitUntil: 'domcontentloaded' });
        const bookingRow = adminPage.locator('tbody tr').filter({ hasText: testEmail });
        await expect(bookingRow).toBeVisible();
        const statusForm = bookingRow.locator('form:has(input[name="wpcb_admin_action"][value="booking_status"])');
        await statusForm.locator('select[name="event"]').selectOption('admin_approved');
        const approveButton = statusForm.getByRole('button', { name: 'Ausführen' });
        await approveButton.focus();
        await Promise.all([
            adminPage.waitForNavigation(),
            adminPage.keyboard.press('Enter'),
        ]);
        const confirmed = await waitForBookingStatus('confirmed');
        const originalStart = confirmed.slot_start;

        // Public rendering must stay busy-only and never expose stored customer PII.
        await visitorPage.goto(shortcodeUrl, { waitUntil: 'networkidle' });
        const publicHtml = await visitorPage.locator('body').innerText();
        expect(publicHtml).not.toContain(testEmail);
        expect(publicHtml).not.toContain(testFirstName + ' ' + testLastName);
        expect(publicHtml).not.toContain('Private browser E2E note');

        // Public admin-post action endpoint rejects GET.
        const cancelUrlBeforeUpdate = await waitForActionMail('cancel');
        const cancelParsed = new URL(cancelUrlBeforeUpdate);
        const forbiddenGet = await visitorContext.request.get(
            baseUrl + '/wp-admin/admin-post.php?action=wpcb_booking_action'
                + '&wpcb_link_action=cancel'
                + '&wpcb_token=' + encodeURIComponent(cancelParsed.searchParams.get('wpcb_token') || '')
        );
        expect(forbiddenGet.status()).toBe(405);
        await waitForBookingStatus('confirmed');

        // Reschedule is also GET-safe and keyboard-submitted.
        const updateUrl = await waitForActionMail('update');
        await visitorPage.goto(updateUrl, { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByRole('heading', { name: 'Termin ändern' })).toBeVisible();
        await waitForBookingStatus('confirmed');
        const newSlotSelect = visitorPage.locator('select[name="new_slot_token"]');
        const newValues = await newSlotSelect.locator('option').evaluateAll((options) =>
            options.map((option) => option.value).filter(Boolean)
        );
        expect(newValues.length).toBeGreaterThan(0);
        await newSlotSelect.selectOption(newValues[0]);
        const updateButton = visitorPage.getByRole('button', { name: 'Termin ändern' });
        await updateButton.focus();
        await Promise.all([
            visitorPage.waitForNavigation(),
            visitorPage.keyboard.press('Enter'),
        ]);
        const rescheduled = await waitForBookingStatus('confirmed');
        expect(rescheduled.slot_start).not.toBe(originalStart);

        // Use the fresh post-reschedule cancel link; GET must not mutate.
        const cancelUrl = await waitForActionMail('cancel');
        await visitorPage.goto(cancelUrl, { waitUntil: 'domcontentloaded' });
        await expect(visitorPage.getByRole('heading', { name: 'Termin stornieren' })).toBeVisible();
        await waitForBookingStatus('confirmed');
        const cancelButton = visitorPage.getByRole('button', { name: 'Termin verbindlich stornieren' });
        await cancelButton.focus();
        await Promise.all([
            visitorPage.waitForNavigation(),
            visitorPage.keyboard.press('Enter'),
        ]);
        await waitForBookingStatus('cancelled');

        expect(pluginErrors).toEqual([]);
    } catch (error) {
        await redactAndScreenshot(lastPage, 'browser-release-failure.png');
        mkdirSync(artifactDir, { recursive: true });
        let safeUrl = '';
        if (lastPage && !lastPage.isClosed()) {
            try {
                const current = new URL(lastPage.url());
                safeUrl = current.origin + current.pathname;
            } catch {
                safeUrl = '';
            }
        }
        writeFileSync(
            path.join(artifactDir, 'failure.txt'),
            'Browser release acceptance failed.\nPage: ' + safeUrl + '\nError: ' + String(error?.message || error) + '\n'
        );
        throw error;
    } finally {
        try {
            fixture('cleanup');
        } catch {
            // The disposable CI WordPress instance is destroyed after the job.
        }
        if (adminContext) await adminContext.close();
        if (visitorContext) await visitorContext.close();
    }
});
