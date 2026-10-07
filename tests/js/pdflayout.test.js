'use strict';

/*
 * The PDF Layout editor (assets/js/admin-pdflayout.js) on the page PDFLayoutEditor::render() prints for the default
 * layout, with the object it localizes (fixture.admin), TESTING.md §6: the live preview follows the "Footer" section
 * toggle as the PDF does, shows the dates as the PDF writes them, and a save shows the server's warning about
 * signatures the layout keeps from some recipients without a reload. That each setting reaches the real PDF is
 * PdfLayoutTest's.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture } = require('./support/page');
const { adminWindow, settle, until, script, type } = require('./support/admin-page');

const DATA = fixture.admin.pdfLayoutPage.data;
const I18N = fixture.admin.pdfLayoutPage.i18n;

async function loadLayout(server) {
    const page = adminWindow(fixture.admin.pdfLayout, server);
    page.window.FabricatorPdfLayoutPage = JSON.parse(JSON.stringify(fixture.admin.pdfLayoutPage));
    page.window.eval(script('admin-pdflayout.js'));
    await settle(page);
    return page;
}

const papers = (page) => Array.from(page.document.querySelectorAll('#fabricator-preview-stage-inner .fabricator-a4-paper'));
const pageNumber = (n, total) => I18N.pageOfPage.replace('%1$s', n).replace('%2$s', total);

function toggleSection(page, slug) {
    page.document.querySelector('.fabricator-section-item[data-slug="' + slug + '"] .fabricator-section-toggle').click();
}

test('the "Footer" switch takes the footer and its page numbers out of the preview, and back', async () => {
    const page = await loadLayout();
    const doc = page.document;
    assert.deepEqual(page.errors, []);
    type(page, doc.getElementById('footer_text'), 'ACME Ltd');
    assert.ok(papers(page).length > 0, 'the preview has pages');
    papers(page).forEach((p, i, all) => {
        assert.match(p.textContent, /ACME Ltd/);
        assert.ok(p.textContent.includes(pageNumber(i + 1, all.length)), 'page ' + (i + 1) + ' is numbered');
    });

    toggleSection(page, 'footer');
    assert.equal(doc.getElementById('fabricator-section-hidden-input').value, 'footer');
    papers(page).forEach((p, i, all) => {
        assert.doesNotMatch(p.textContent, /ACME Ltd/, 'no footer text');
        assert.equal(p.textContent.includes(pageNumber(i + 1, all.length)), false, 'no page number');
    });

    toggleSection(page, 'footer');
    assert.equal(doc.getElementById('fabricator-section-hidden-input').value, '');
    assert.ok(papers(page)[0].textContent.includes('ACME Ltd'));
});

test('the preview dates the sample as the PDF does: the site\'s time in the metadata, its date format in the footer', async () => {
    const page = await loadLayout();
    type(page, page.document.getElementById('footer_text'), 'Printed {date}');
    const text = papers(page).map((p) => p.textContent).join('\n');

    assert.ok(text.includes(I18N.created + ' ' + DATA.createdDate), 'the metadata block names the site\'s time');
    assert.ok(text.includes('Printed ' + DATA.footerDate), 'the footer\'s {date} is the site\'s date');
    // The PDF preview is made for no form, so it names no form ID (layout.php), and the live preview matches it.
    assert.ok(text.includes(I18N.formLabel + ' ' + I18N.sampleFormName));
    assert.doesNotMatch(text, /\(ID:/);
});

test('a save shows the server\'s warning about signatures without a reload, and a later save without one removes it', async () => {
    const warnings = [
        'This layout leaves signatures out of the PDF. These forms take signatures, and the recipients of their notifications without "Attach uploaded files" get none: Contact.',
        '',
    ];
    let saves = 0;
    const page = await loadLayout((url, body) => (body.action === 'fabricator_save_pdf_layout'
        ? { success: true, data: { message: 'Saved.', warning: warnings[saves++], snapshot: 'snap-' + saves } }
        : { success: true, data: {} }));
    const doc = page.document;
    const warn = doc.getElementById('fabricator-pdf-signature-warning');
    const form = doc.getElementById('fabricator-pdf-layout-form');
    assert.equal(warn.hidden, true, 'the default layout warns about nothing');

    toggleSection(page, 'signatures');
    form.requestSubmit();
    await until(page, () => saves === 1 && !warn.hidden);
    const sent = page.requests.find((r) => r.body && r.body.action === 'fabricator_save_pdf_layout');
    assert.equal(sent.body.section_hidden, 'signatures', 'the hidden section is sent');
    assert.equal(warn.textContent.trim(), warnings[0]);
    assert.equal(form.querySelector('[name="fabricator_pdf_layout_snapshot"]').value, 'snap-1');

    toggleSection(page, 'signatures');
    form.requestSubmit();
    await until(page, () => saves === 2 && warn.hidden);
    assert.equal(warn.textContent.trim(), '');
    assert.deepEqual(page.errors, []);
});
