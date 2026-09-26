'use strict';

/*
 * front.js guards for things outside the plugin's control: a blocked reCAPTCHA script (1.0.2) and a page restored
 * from the back/forward cache (a second submission, a stale message).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadPage } = require('./support/page');

function captchaGate() {
    const page = loadPage('<div class="fabricator-captcha-gate" data-sitekey="x">'
        + '<button type="button" class="fabricator-captcha-activate" disabled></button></div>');
    return {
        page,
        gate: page.document.querySelector('.fabricator-captcha-gate'),
        btn: page.document.querySelector('.fabricator-captcha-activate'),
    };
}

test('a blocked CAPTCHA re-enables the button so the visitor is not stuck', () => {
    const { page, gate, btn } = captchaGate();
    page.hooks.showCaptchaBlockedNotice(gate, btn);
    assert.equal(btn.disabled, false);
});

test('a blocked CAPTCHA shows the localized notice once, however often it is reported', () => {
    const { page, gate, btn } = captchaGate();
    page.hooks.showCaptchaBlockedNotice(gate, btn);
    page.hooks.showCaptchaBlockedNotice(gate, btn);
    const notices = gate.querySelectorAll('.fabricator-notice.fabricator-error');
    assert.equal(notices.length, 1);
    assert.equal(notices[0].textContent, page.window.FabricatorForms.i18n.recaptcha_blocked);
});

test('a bfcache restore clears the "submitted" flag and hides the old message', () => {
    const page = loadPage('<div class="fabricator-form-wrap">'
        + '<div class="fabricator-form-messages" style="display:block;">Thank you!</div>'
        + '<form class="fabricator-form"><button type="submit" class="fabricator-submit-btn" data-fabricator-submitted="1"></button></form></div>');
    page.hooks.resetFormsOnBfcacheRestore();
    assert.equal('fabricatorSubmitted' in page.document.querySelector('.fabricator-submit-btn').dataset, false, 'a second click would submit again');
    assert.equal(page.document.querySelector('.fabricator-form-messages').style.display, 'none');
});

test('front.js runs the reset on a real persisted pageshow event', () => {
    const page = loadPage('<div class="fabricator-form-wrap"><div class="fabricator-form-messages" style="display:block;">Thank you!</div>'
        + '<form class="fabricator-form"><button type="submit" class="fabricator-submit-btn" data-fabricator-submitted="1"></button></form></div>');
    page.window.dispatchEvent(new page.window.PageTransitionEvent('pageshow', { persisted: true }));
    assert.equal(page.document.querySelector('.fabricator-form-messages').style.display, 'none');
});
