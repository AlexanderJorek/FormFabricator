'use strict';

/*
 * Whole forms as FormRenderer::render() prints them (fixture.forms), driven the way a visitor drives them: the submit
 * button's conditions, page navigation, submitting with the server's answer, two forms on one page, the CAPTCHA widget
 * after a failed submission, and the keyboard paths of the rating and the dropdown. The server is a fetch() stub that
 * answers as admin-ajax.php does. TESTING.md §2, §3 and §4 name what each test took over.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { fixture, loadPage } = require('./support/page');

/** A page with `html`, once front.js has booted on DOMContentLoaded as it does on a real page. */
async function open(html) {
    const page = loadPage(html);
    if (page.document.readyState === 'loading') {
        await new Promise((resolve) => page.document.addEventListener('DOMContentLoaded', () => page.window.setTimeout(resolve, 0)));
    }
    assert.equal(page.window.__fabricatorFrontInited, true, 'front.js booted');
    assert.deepEqual(page.errors, []);
    return page;
}

/** A settled page: front.js's fetch() chains run on microtasks and timers. */
async function settle(page) {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => page.window.setTimeout(resolve, 0));
    }
}

/**
 * Answers front.js's two requests: the token, then the submission with `answer`. Records every submission's fields.
 */
function stubServer(page, answer) {
    const submissions = [];
    page.window.fetch = (url, opts) => {
        const body = opts.body;
        const isToken = body instanceof page.window.URLSearchParams && body.get('action') === 'fabricator_forms_get_token';
        if (!isToken) {
            submissions.push(Array.from(body.entries()).filter(([, v]) => typeof v === 'string'));
        }
        const json = isToken ? { success: true, data: { nonce: 'n', token: 't' } } : answer;
        return Promise.resolve({ json: () => Promise.resolve(json) });
    };
    return submissions;
}

/** A reCAPTCHA stand-in whose widget is solved at once: it holds its token in g-recaptcha-response, as the real one does. */
function solvedCaptcha(widgetId, resets) {
    return {
        render(el) {
            el.innerHTML = '<textarea name="g-recaptcha-response">token-' + widgetId + '</textarea>';
            return widgetId;
        },
        reset: (id) => resets.push(id),
    };
}

function submit(page, form) {
    form.dispatchEvent(new page.window.Event('submit', { bubbles: true, cancelable: true }));
}

function type(page, input, value) {
    input.value = value;
    input.dispatchEvent(new page.window.Event('input', { bubbles: true }));
}

/** The submit button is shown when neither the conditions nor the paging hide the footer. */
function submitShown(form) {
    const footer = form.querySelector('.fabricator-form-footer');
    return footer.style.display !== 'none' && !footer.classList.contains('fabricator-footer-off-page');
}

test('submit condition: the button is hidden from the start, shows while the condition is met, hides again', async () => {
    const page = await open(fixture.forms['submit condition']);
    const form = page.document.querySelector('form');
    const code = form.querySelector('[name="code"]');

    assert.equal(submitShown(form), false, 'hidden on load, before any input event');
    type(page, code, 'open');
    assert.equal(submitShown(form), true);
    type(page, code, 'closed');
    assert.equal(submitShown(form), false);
});

test('submit condition over three pages: hidden on earlier pages even while met, and on the last page while unmet', async () => {
    const page = await open(fixture.forms['submit condition, three pages']);
    const form = page.document.querySelector('form');
    const next = () => form.querySelector('.fabricator-page-active .fabricator-btn-next').click();

    type(page, form.querySelector('[name="code"]'), 'open');
    assert.equal(submitShown(form), false, 'page 1, condition met');
    next();
    assert.equal(submitShown(form), false, 'page 2, condition met');
    next();
    assert.equal(submitShown(form), true, 'last page, condition met');

    form.querySelector('.fabricator-page-active .fabricator-btn-prev').click();
    form.querySelector('.fabricator-page-active .fabricator-btn-prev').click();
    type(page, form.querySelector('[name="code"]'), 'nope');
    next();
    next();
    assert.equal(submitShown(form), false, 'last page, condition not met');
});

test('page navigation back and forth keeps what was entered on every page', async () => {
    const page = await open(fixture.forms['submit condition, three pages']);
    const form = page.document.querySelector('form');
    const active = () => form.querySelector('.fabricator-page-active');

    type(page, form.querySelector('[name="code"]'), 'first');
    active().querySelector('.fabricator-btn-next').click();
    type(page, form.querySelector('[name="middle"]'), 'second');
    active().querySelector('.fabricator-btn-next').click();
    type(page, form.querySelector('[name="last"]'), 'third');

    active().querySelector('.fabricator-btn-prev').click();
    active().querySelector('.fabricator-btn-prev').click();
    assert.equal(active().querySelector('[name="code"]') !== null, true, 'back on page 1');
    active().querySelector('.fabricator-btn-next').click();
    active().querySelector('.fabricator-btn-next').click();

    assert.deepEqual(
        ['code', 'middle', 'last'].map((n) => form.querySelector('[name="' + n + '"]').value),
        ['first', 'second', 'third']
    );
});

/** Records window.scrollTo() calls, which jsdom doesn't implement. */
function recordScrolls(page) {
    const scrolls = [];
    page.window.scrollTo = (...args) => scrolls.push(args);
    return scrolls;
}

test('a server error for a field on an earlier page shows that page with the error', async () => {
    const page = await open(fixture.forms['submit condition, three pages']);
    const form = page.document.querySelector('form');
    const active = () => form.querySelector('.fabricator-page-active');
    type(page, form.querySelector('[name="code"]'), 'open');
    active().querySelector('.fabricator-btn-next').click();
    active().querySelector('.fabricator-btn-next').click();
    assert.notEqual(active().querySelector('[name="last"]'), null, 'on the last page');

    const scrolls = recordScrolls(page);
    stubServer(page, { success: false, data: { message: 'Please correct the highlighted fields.', errors: { code: 'Not accepted.' } } });
    submit(page, form);
    await settle(page);

    assert.notEqual(active().querySelector('[name="code"]'), null, 'back on the page holding the field');
    assert.equal(page.document.querySelector('#code-error').textContent, 'Not accepted.');
    assert.equal(scrolls.length, 1);
});

test('a server error without a field to show brings the message into view', async () => {
    for (const [label, fetchStub] of [
        ['a response that is no JSON', () => Promise.resolve({ json: () => Promise.reject(new SyntaxError('Unexpected token <')) })],
        ['an unreachable server', () => Promise.reject(new Error('offline'))],
    ]) {
        const page = await open(fixture.forms['contact']);
        const form = page.document.querySelector('form');
        type(page, form.querySelector('[name="name"]'), 'Ada');
        const scrolls = recordScrolls(page);
        page.window.fetch = fetchStub;

        submit(page, form);
        await settle(page);

        const box = page.document.querySelector('.fabricator-form-messages');
        assert.equal(box.textContent, 'Server error. Please try again.', label);
        assert.equal(scrolls.length, 1, label + ': scrolled to the message');
    }

    const page = await open(fixture.forms['contact']);
    const form = page.document.querySelector('form');
    type(page, form.querySelector('[name="name"]'), 'Ada');
    const scrolls = recordScrolls(page);
    stubServer(page, { success: false, data: { message: 'The server is busy processing other submissions. Please try again in a moment.' } });
    submit(page, form);
    await settle(page);
    assert.equal(scrolls.length, 1, 'a refusal without field errors: scrolled to the message');
});

test('the same form twice: each copy sends its own values, and server errors land in the copy that was sent', async () => {
    const page = await open(fixture.forms['contact twice']);
    const [first, second] = page.document.querySelectorAll('form');
    const submissions = stubServer(page, { success: false, data: { message: 'Please correct the highlighted fields.', errors: { email: 'This address is not accepted.' } } });
    type(page, first.querySelector('[name="name"]'), 'In the first copy');
    type(page, second.querySelector('[name="name"]'), 'Ada');
    type(page, second.querySelector('[name="email"]'), 'ada@example.org');

    submit(page, second);
    await settle(page);

    assert.equal(submissions.length, 1);
    const sent = Object.fromEntries(submissions[0]);
    assert.equal(sent.name, 'Ada');
    assert.equal(sent.email, 'ada@example.org');
    assert.equal(page.document.querySelector('#email-error--2').textContent, 'This address is not accepted.');
    assert.equal(page.document.querySelector('#email-error').textContent, '', 'nothing in the copy that was not sent');
});

test('the same form twice: each label belongs to the input of its own copy', async () => {
    // Which element a click on a label focuses (label.control); the focusing itself is the browser's.
    const page = await open(fixture.forms['contact twice']);
    page.document.querySelectorAll('form').forEach((form) => {
        form.querySelectorAll('label[for]').forEach((label) => {
            assert.notEqual(label.control, null, label.htmlFor + ' labels something');
            assert.equal(label.control.form, form, label.htmlFor + ' labels an input of its own form');
        });
    });
    const second = page.document.querySelectorAll('form')[1];
    assert.equal(second.querySelector('label[for^="name"]').control, second.querySelector('[name="name"]'));
});

test('two different forms sharing a field id: each sends its own values and labels stay in their form', async () => {
    const page = await open(fixture.forms['contact and callback']);
    const [contact, callback] = page.document.querySelectorAll('form');
    const submissions = stubServer(page, { success: true, data: { message: 'Thanks' } });
    type(page, contact.querySelector('[name="name"]'), 'Ada');
    type(page, contact.querySelector('[name="email"]'), 'ada@example.org');
    type(page, callback.querySelector('[name="email"]'), 'bob@example.org');
    type(page, callback.querySelector('[name="phone"]'), '0123');

    assert.equal(callback.querySelector('label[for^="email"]').control, callback.querySelector('[name="email"]'));
    assert.equal(contact.querySelector('label[for^="email"]').control, contact.querySelector('[name="email"]'));

    submit(page, callback);
    await settle(page);
    submit(page, contact);
    await settle(page);

    assert.deepEqual(
        submissions.map((s) => Object.fromEntries(s.filter(([k]) => ['name', 'email', 'phone'].includes(k)))),
        [{ email: 'bob@example.org', phone: '0123' }, { name: 'Ada', email: 'ada@example.org' }]
    );
});

test('CAPTCHA: a failed submission resets the solved widget, the other field is named, and the retry is sent', async () => {
    // TESTING.md §3: solve the CAPTCHA, submit with another field invalid: the error names that field and the widget
    // clears itself, since its token is single-use.
    const page = await open(fixture.forms['with captcha']);
    const form = page.document.querySelector('form');
    const resets = [];
    page.window.grecaptcha = solvedCaptcha(7, resets);
    form.querySelector('.fabricator-captcha-activate').click();
    assert.equal(form.querySelector('.fabricator-captcha-gate').dataset.fabricatorCaptchaWidget, '7', 'the widget is rendered');

    let submissions = stubServer(page, { success: false, data: { message: 'Please correct the highlighted fields.', errors: { name: 'Name: Required field.' } } });
    submit(page, form);
    await settle(page);

    assert.equal(submissions.length, 1);
    assert.deepEqual(resets, [7], 'the used token is cleared for a new one');
    assert.equal(page.document.querySelector('#name-error').textContent, 'Name: Required field.');
    assert.equal(page.document.querySelector('#cap-error').textContent, '');

    submissions = stubServer(page, { success: true, data: { message: 'Thanks' } });
    type(page, form.querySelector('[name="name"]'), 'Ada');
    submit(page, form);
    await settle(page);
    assert.equal(submissions.length, 1, 'solving it again and resubmitting works');
});

test('CAPTCHA: an unreachable server also clears the widget', async () => {
    const page = await open(fixture.forms['with captcha']);
    const form = page.document.querySelector('form');
    const resets = [];
    page.window.grecaptcha = solvedCaptcha(3, resets);
    form.querySelector('.fabricator-captcha-activate').click();
    page.window.fetch = () => Promise.reject(new Error('offline'));

    submit(page, form);
    await settle(page);

    assert.deepEqual(resets, [3]);
});

test('rating by keyboard: the radios are reachable, the stars follow the arrow keys, a required rating is satisfied', async () => {
    // A browser moves between radios with the arrow keys and fires change; jsdom has no key handling, so the change
    // is what the test sends.
    const page = await open(fixture.forms['rating']);
    const form = page.document.querySelector('form');
    const radios = Array.from(form.querySelectorAll('input[name="stars"]'));

    assert.equal(radios.length, 5);
    assert.equal(radios.every((r) => !r.disabled && r.tabIndex !== -1), true, 'Tab reaches the group');
    assert.equal(page.hooks.validatePage(form).valid, false, 'required, nothing chosen');

    radios[3].checked = true;
    radios[3].dispatchEvent(new page.window.Event('change', { bubbles: true }));

    const full = Array.from(form.querySelectorAll('.fabricator-rating-star--full')).map((s) => s.dataset.star);
    assert.deepEqual(full, ['1', '2', '3', '4']);
    assert.equal(page.hooks.validatePage(form).valid, true);
});

test('dropdown: Tab reaches it exactly once, its label names it, and clicking the label focuses it', async () => {
    const page = await open(fixture.forms['dropdown']);
    const field = page.document.querySelector('.fabricator-field--select');
    const tabbable = Array.from(field.querySelectorAll('input, select, textarea, button, [tabindex]'))
        .filter((el) => el.tabIndex >= 0 && !el.disabled);

    assert.equal(tabbable.length, 1, 'one stop for the keyboard: ' + tabbable.map((e) => e.outerHTML.slice(0, 60)).join(' | '));
    const custom = tabbable[0];
    const label = page.document.getElementById(custom.getAttribute('aria-labelledby'));
    assert.equal(label.textContent.trim().startsWith('Size'), true, 'named by the field label');

    label.click();
    assert.equal(page.document.activeElement, custom);
});
