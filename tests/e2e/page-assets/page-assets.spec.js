'use strict';

/*
 * The WordPress.org plugin page's images, made from the real plugin on the E2E site: the icon and banner from the admin
 * menu's own icon, and four screenshots of a membership application with a SEPA mandate. Run by
 * `php tools/page-assets.php`, which names the output folder (FABRICATOR_PAGE_ASSETS, the repository's
 * .wordpress-org/); no test run includes this project. The screenshots' captions are readme.txt's "Screenshots"
 * section, in the same order.
 */

const fs = require('node:fs');
const path = require('node:path');
const { test, expect, firefox } = require('@playwright/test');
const { auth, wp, uniqueName, notification, mailWithSubject, openForm } = require('../support/site');

const OUT = process.env.FABRICATOR_PAGE_ASSETS;
const FILES = path.join(process.env.FABRICATOR_E2E_CONTENT || '', 'e2e-outbox', 'files');
// The admin screens' accent, which the icon and banner share.
const BLUE = '#2271b1';
const BLUE_DARK = '#135e96';
const TAGLINE = 'Forms by email, with sealed PDFs. Nothing stored in WordPress.';

test.describe.configure({ mode: 'serial' });
test.setTimeout(180000);

const out = (name) => path.join(OUT, name);

/** The admin menu's FormFabricator icon, as SVG markup. */
async function menuIcon(page) {
    await page.goto('/wp-admin/');
    const background = await page.locator('#toplevel_page_fabricator-forms .wp-menu-image').evaluate((el) => getComputedStyle(el).backgroundImage);
    const base64 = /base64,([^")]+)/.exec(background);
    expect(base64, 'the menu icon as a data: URI').not.toBeNull();
    return Buffer.from(base64[1], 'base64').toString('utf8');
}

/** The icon's SVG at $size pixels, white. */
function whiteIcon(svg, size) {
    return svg.replace('<svg ', '<svg width="' + size + '" height="' + size + '" fill="#ffffff" ');
}

/** Removes what only the test site shows: its sample admin notice. */
async function tidyAdmin(page) {
    await page.evaluate(() => document.querySelectorAll('#e2e-sample-notice').forEach((n) => n.remove()));
}

/** Draws a handwritten-looking signature on `canvas`. */
async function sign(page, canvas) {
    const box = await canvas.boundingBox();
    const at = (x, y) => [box.x + box.width * x, box.y + box.height * y];
    const stroke = async (points) => {
        await page.mouse.move(...at(...points[0]));
        await page.mouse.down();
        for (const p of points.slice(1)) {
            await page.mouse.move(...at(...p), { steps: 3 });
        }
        await page.mouse.up();
    };
    // A capital loop, then a run of joined lower-case waves, then a flourish under it.
    const capital = [];
    for (let t = 0; t <= Math.PI * 2.2; t += 0.25) {
        capital.push([0.16 + 0.05 * Math.sin(t), 0.5 - 0.28 * Math.cos(t) * (1 - t / 9)]);
    }
    await stroke(capital);
    const word = [];
    for (let x = 0.22; x <= 0.7; x += 0.012) {
        word.push([x, 0.58 - 0.13 * Math.abs(Math.sin((x - 0.22) * 34)) - (x > 0.42 && x < 0.47 ? 0.18 : 0)]);
    }
    await stroke(word);
    await stroke([[0.12, 0.78], [0.3, 0.74], [0.5, 0.73], [0.72, 0.7], [0.82, 0.62]]);
}

let pdfFile = '';

test.describe('admin', () => {
    test.use({ storageState: auth.admin });

    test('icon and banner', async ({ page, browser }) => {
        const svg = await menuIcon(page);
        for (const size of [128, 256]) {
            const ctx = await browser.newContext({ viewport: { width: size, height: size } });
            const p = await ctx.newPage();
            await p.setContent('<body style="margin:0;width:' + size + 'px;height:' + size + 'px;display:flex;align-items:center;'
                + 'justify-content:center;background:linear-gradient(135deg,' + BLUE + ',' + BLUE_DARK + ')">'
                + whiteIcon(svg, Math.round(size * 0.74)) + '</body>');
            await p.screenshot({ path: out('icon-' + size + 'x' + size + '.png') });
            await ctx.close();
        }
        // One layout at 772 × 250, taken at 1x and 2x for the two banner sizes WordPress.org asks for.
        const banner = '<body style="margin:0;width:772px;height:250px;display:flex;align-items:center;gap:36px;'
            + 'padding:0 56px;box-sizing:border-box;background:linear-gradient(120deg,' + BLUE + ' 0%,' + BLUE_DARK + ' 100%);'
            + 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#fff">'
            + whiteIcon(svg, 132)
            + '<div><div style="font-size:52px;font-weight:700;letter-spacing:-.5px;line-height:1.1">FormFabricator</div>'
            + '<div style="font-size:21px;margin-top:14px;opacity:.92;line-height:1.35;max-width:470px">' + TAGLINE + '</div></div>'
            + '</body>';
        for (const [scale, name] of [[1, 'banner-772x250.png'], [2, 'banner-1544x500.png']]) {
            const ctx = await browser.newContext({ viewport: { width: 772, height: 250 }, deviceScaleFactor: scale });
            const p = await ctx.newPage();
            await p.setContent(banner);
            await p.screenshot({ path: out(name) });
            await ctx.close();
        }
    });

    test('screenshots: builder, form, PDF, verification', async ({ page, browser }) => {
        const subject = uniqueName('Membership application');
        const { id } = wp({
            do: 'form',
            title: 'Membership application',
            fields: [
                { id: 'name', type: 'name', label: 'Name', expanded: true, fname_enabled: true, lname_enabled: true, required: true },
                { id: 'email', type: 'email', label: 'Email', required: true },
                {
                    id: 'fee', type: 'directdebit', label: 'Membership fee', scheme: 'sepa', required: true,
                    // Example data only: SWIFT's example IBAN (filled in below), the Bundesbank's sample creditor ID,
                    // and a creditor that is plainly an example.
                    creditor_name: 'Example Sports Club', creditor_id: 'DE98ZZZ09999999999',
                    creditor_address: 'Example Street 1, 12345 Example City',
                },
                { id: 'statutes', type: 'consent', label: 'Statutes', required: true, consent_text: 'I have read the club statutes and agree to them.' },
            ],
            notifications: [notification({ subject, attach_pdf: true })],
        });
        const form = wp({ do: 'page', title: 'Membership application', content: '[fabricator_form id="' + id + '"]' });

        // 1. The builder.
        await page.setViewportSize({ width: 1440, height: 640 });
        await page.goto('/wp-admin/admin.php?page=fabricator-forms-editor&form_id=' + id);
        await expect(page.locator('#fabricator-field-list')).toBeVisible();
        await tidyAdmin(page);
        await page.screenshot({ path: out('screenshot-1.png') });

        // 2. The form, filled in by a visitor.
        const visitor = await browser.newContext({ viewport: { width: 900, height: 1400 } });
        const v = await visitor.newPage();
        await openForm(v, form.url);
        await v.locator('[name="name[fname]"]').fill('Ada');
        await v.locator('[name="name[lname]"]').fill('Lovelace');
        await v.locator('[name="email"]').fill('ada@example.org');
        await v.locator('[name="fee[iban]"]').pressSequentially('DE89370400440532013000');
        await v.locator('[name="fee[holder]"]').fill('Ada Lovelace');
        await sign(v, v.locator('[data-field-id="fee"] .fabricator-signature-canvas'));
        await v.locator('[data-field-id="statutes"] input[type="checkbox"]').check();
        await v.locator('[name="fee[holder]"]').click();
        // The test theme sets no margins: a white frame around the form, as a theme's content area would give it.
        await v.evaluate(() => {
            document.body.style.background = '#f0f0f1';
            const wrap = document.querySelector('.fabricator-form-wrap');
            Object.assign(wrap.style, { background: '#fff', padding: '32px', margin: '24px', borderRadius: '8px', boxShadow: '0 2px 12px rgba(0,0,0,.08)' });
        });
        await v.locator('.fabricator-form-wrap').first().screenshot({ path: out('screenshot-2.png') });
        await v.locator('.fabricator-submit-btn').click();
        await expect(v.locator('.fabricator-form-messages.success')).toBeVisible();
        await visitor.close();

        // 3. The sealed PDF the notification carried, as a PDF viewer shows it (Firefox's PDF.js).
        const mail = await mailWithSubject(subject);
        const name = mail.attachments.find((a) => a.toLowerCase().endsWith('.pdf'));
        expect(name, 'the PDF attachment').toBeTruthy();
        pdfFile = path.join(FILES, name);
        // channel: none of its own; the project names Chrome's, which the runner hands every browser it starts.
        const ff = await firefox.launch({ channel: undefined, firefoxUserPrefs: { 'pdfjs.disabled': false } });
        const viewer = await ff.newPage({ viewport: { width: 1700, height: 1200 } });
        await viewer.route('http://pdf.local/submission.pdf', (r) => r.fulfill({
            status: 200, contentType: 'application/pdf', headers: { 'Content-Disposition': 'inline' }, body: fs.readFileSync(pdfFile),
        }));
        await viewer.goto('http://pdf.local/submission.pdf');
        await expect(viewer.locator('#viewer .page[data-page-number="1"] canvas').first()).toBeVisible();
        // Two pages side by side, whole: the mandate's signature is on the second.
        await viewer.evaluate(() => {
            const app = window.wrappedJSObject ? window.wrappedJSObject.PDFViewerApplication : window.PDFViewerApplication;
            app.pdfViewer.spreadMode = 1;
            app.pdfViewer.currentScaleValue = 'page-fit';
        });
        await expect(viewer.locator('#viewer .page[data-page-number="2"] canvas').first()).toBeVisible();
        await viewer.waitForTimeout(1500);
        await viewer.locator('#viewer .spread').first().screenshot({ path: out('screenshot-3.png') });
        await ff.close();

        // 4. The verification page, checking that PDF.
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/wp-admin/admin.php?page=fabricator-forms-pdf-verification');
        await page.locator('#pdf-input').setInputFiles(pdfFile);
        await expect(page.locator('#fabricator-pdf-verify-btn')).toBeEnabled();
        await page.locator('#fabricator-pdf-verify-btn').click();
        // Its verdict, opened to show what was compared.
        const verdict = page.locator('.fabricator-pdf-toggle', { hasText: 'Authentic' }).first();
        await expect(verdict).toBeVisible({ timeout: 60000 });
        await verdict.click();
        await expect(page.locator('.fabricator-pdf-row-ok').first()).toBeVisible();
        await expect(page.locator('.fabricator-pdf-row-fail')).toHaveCount(0);
        await tidyAdmin(page);
        await page.screenshot({ path: out('screenshot-4.png') });
    });
});
