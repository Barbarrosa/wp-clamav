/* Optional development check: npm Playwright plus Chromium, no production dependency. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const fixture = JSON.parse(fs.readFileSync('/workspace/wp-integration-fixture.json'));
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
        headless: true, args: ['--no-sandbox'] });
    try {
        for (const mode of ['single', 'submission', 'ajax']) {
            const context = await browser.newContext();
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto('http://127.0.0.1:8081/?page_id=' + fixture.pages[mode]);
            await page.waitForSelector('form.forminator-initialized');
            const narrative = 'Browser narrative without required attachment ' + mode + ' ' + Date.now();
            await page.locator('input[name="text-1"]').fill(narrative);
            const response = page.waitForResponse(item => item.url().includes('admin-ajax.php')
                && item.request().postData()?.includes('forminator_submit_form_custom-forms'), { timeout: 15000 });
            await page.getByRole('button', { name: 'Send', exact: true }).click();
            const result = await (await response).json();
            assert.equal(result.success, true);
            assert.equal(result.data.success, true);
            assert.match(result.data.message, /Keep your originals/);
            const state = await (await context.request.get('http://127.0.0.1:8081/?rest_route=/wp-clamav-tests/v1/state',
                { headers: { 'X-WP-ClamAV-Test': 'integration-only-route-token' } })).json();
            assert.ok(state.submissions.some(item => JSON.stringify(item).includes(narrative)));
            assert.deepEqual(errors, []);
            console.log('PASS browser ' + mode + ': required attachment does not block a durable narrative');
            await context.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
