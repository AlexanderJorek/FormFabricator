# FormFabricator — Manual Verification Guide

Dev-only. Not shipped in the release package (excluded in `build.ps1`, same as `CLAUDE.md`).

This checklist holds only what still needs a person: real hosts and mail servers, real browsers and
devices, a screen reader, a human eye on the PDF, and admin-screen flows. Everything else runs
automatically — see "What is automated" below — and an item moves out of this list once a test
reproduces it. Section numbers are stable (the test suites refer to them); a section with nothing
left to do by hand says so.

Test on a real WordPress install (PHP 8.1+, WP 6.5+). Use a fresh test form for each section
unless noted otherwise.

## What is automated

Release gates in `build.ps1`: `php -l`, `vendor/bin/phpcs` (PSR),
`vendor/bin/phpcs --standard=.phpcs-security.xml` (errors), `php languages/make-pot.php --check`,
`composer audit`, `composer test` (PHPUnit `unit` + `perf`), `npm test` (JS), the WordPress
integration suite on a single site and a network (against a throwaway MariaDB, `build-testdb.ps1`),
and the package verification step (no dev files in the zip, pdf.js hashes, font trim). CI
(`.github/workflows/tests.yml`) runs the same on PHP 8.1–8.4 and Node 22/24, with MySQL 8.

| Area | Covered by |
|---|---|
| Every field type's render / validate / map / sanitize, incl. date formats and ranges, Address one-line, IBAN/BIC checks, blocked upload extensions, HTML block "Show in mail/PDF", GDPR/Consent timestamps | `tests/Integration/Fields/FieldBehaviourTest.php` (the former WP_DEBUG field test page) |
| Label/group markup screen readers rely on; unique ids for the same form twice | `tests/Unit/Fields/FieldLabelsTest.php`, `tests/Unit/Form/UniqueIdsTest.php` |
| Import with wrong-kind settings (lists where a value belongs) still renders | `tests/Unit/Fields/ImportTypesTest.php` |
| Client validators (incl. Currency two decimals, Number step), `validatePage`, conditions incl. checkbox `id[]`, hidden fields never validated (Captcha, GDPR, Consent), blocked-reCAPTCHA notice, back/forward-cache reset | `tests/js/*.test.js` |
| Client and server agree on every condition | `tests/js/condition-parity.test.js`, `tests/Unit/Form/ConditionsTest.php` |
| Submission end to end: To/CC/BCC, `{field}` placeholders in recipients and headers, blank placeholders, header injection, required fields, duplicate token, forged token/nonce, 10 sends per 5 min per address, no rate-limit rows from page views, mail failure and retry, one of two notifications failing (log names form and notification, no address), all notifications disabled, submit-button conditions on the server, hidden fields not mailed, honeypot | `tests/Integration/Form/SubmissionTest.php` |
| Routing rules ("is empty" vs "[No entry]", 20,00 € > 10, option labels in groups) | `tests/Unit/Form/RoutingRulesTest.php` |
| Image admission and the §3 numbers for a 512 MB budget | `tests/Unit/Form/ImageAdmissionTest.php` |
| Rate limiter, one-time token, concurrency slots, option lock on real SQL | `tests/Integration/Utils/DatabaseHelpersTest.php` |
| Seal key handling: no silent key creation, damaged/undecryptable keys, rotation, retired keys, lock contention | `tests/Unit/PDF/HashSealTest.php`, `HashSealMasterKeyTest.php` |
| Real sealed PDF verifies; edited page, shadow-attack incremental update, unknown key and foreign PDF are caught; rotated key still verifies | `tests/Integration/PDF/SealRoundTripTest.php` |
| No outbound request from HTML-block SSRF payloads (incl. the §4 cases) or IBAN validation; own-host and relative images kept | `tests/Integration/PDF/OutboundRequestsTest.php`, `tests/Unit/Form/LinkSafetyTest.php` |
| Pathological PDFs: stream keywords without `endstream`, many small streams, > 50,000 objects, `>>` without `<<`, decompression bombs, LZW/RunLength/double-compressed streams — time scaling and peak memory | `tests/Perf/*`, `tests/Unit/PDF/*` |
| Verifier temp files expire ~10 min after last use, even without WP-Cron | `tests/Unit/Utils/VerifierCleanupTest.php` |
| Admin screens registered with `fabricator_access_*` capabilities; per-role and per-user grants; Subscriber and logged-out refused | `tests/Integration/Admin/AccessControlTest.php` |
| Uninstall removes forms, options, counters, transients, cron events and user meta — on every site of a network | `tests/Integration/UninstallTest.php` |

## 0. Environment matrix

- [ ] At least one **non-Windows web host** (Linux with Nginx or Apache) for the smoke path — CI covers
      PHP on Linux, but not real HTTP uploads through a web server, and 1.0.7's changelog names a
      Windows-specific upload/PDF-check regression.
- [ ] Multisite network activate and deactivate (network uninstall is automated).
- [ ] A real outgoing mail transport (SMTP plugin or the host's mailer) — confirm mail actually
      arrives at To, CC and BCC. The tests stop at WordPress's mock mailer.

## 1. First-run setup

- [ ] Fresh activation on a site with no prior FormFabricator data redirects to
      **FormFabricator → Settings** and blocks form creation until the one-time PDF seal key setup
      is completed.
- [ ] **Settings → PDF Seal Key** offers the new key for backup/download.
- [ ] Delete or corrupt the `fabricator_forms_seal_key` option: the next admin load shows a notice, and
      a form that attaches a sealed PDF answers visitors with a retry error. "Rotate PDF key" under
      Settings → Security makes submissions work again. (That no replacement key appears silently is
      automated.)
- [ ] Standard-mode site: the "Standard — unencrypted" button in Settings → Security shows a
      `define()` line; after adding it to `wp-config.php` and confirming, the card reads "Encrypted".
- [ ] The admin notice with the server rule for the protected PDF folder appears when that folder is
      reachable over HTTP: Nginx/Caddy, and Nginx serving static files in front of Apache
      (Plesk/cPanel). It must not appear on plain Apache, where `.htaccess` blocks the folder. The
      check is a loopback request queued as a one-off cron event (`fabricator_uploads_probe_run`)
      when an admin page loads without a cached answer, so that first load still uses the
      server-name fallback. Only a 403 counts as protected. A definite answer is cached for a day, an
      inconclusive one (timeout, 401, 404, 5xx, WAF page) for an hour, both in the
      `fabricator_uploads_probe` transient. To re-run it, delete the transient and load an admin page
      twice with WP-Cron running.

## 2. Form builder (FormFabricator → New Form / Editor)

- [ ] Drag every palette group onto the canvas at least once: Input, Choice, Personal, Advanced,
      Layout, System.
- [ ] Reorder fields via drag; confirm order persists after save and reload.
- [ ] Two side-by-side fields render side by side on the front end, and stay that way once a
      conditional-logic rule makes them visible (1.0.1).
- [ ] Delete a field, save, reload — confirm it's gone from both the builder and the front end.
- [ ] Click a page break in the builder — a settings panel opens, the Back and Next labels can be
      changed and take effect on the front end, the panel has no Conditions tab, and a page break
      still cannot be dropped inside a field group (1.0.7).
- [ ] With a screen reader, listen to a checkbox set, a radio set, a star rating, and a name or
      address with sub-fields on: the question is announced as the name of the group (1.0.7). The
      markup behind it is automated; the announcement is not.
- [ ] Two *different* forms on one page through a form selection, each with a field of the same id
      (e.g. `email`): clicking a question focuses the answer in its own form, and each form submits
      its own values (1.0.7).
- [ ] Set up a direct and a group condition in the builder, each as "show if" and "hide if", and
      confirm the front end follows them (the evaluation itself is automated).
- [ ] Two admins on the same form: the second tab shows the "currently being edited by" notice, which
      clears promptly after the first tab closes (1.0.2). With the notice dismissed, save from both
      tabs at nearly the same moment — one save is refused, not both accepted (1.0.7).
- [ ] Edit a field inside a group: a setting that reveals other settings (e.g. the Address field's
      "Sub-fields" switch) follows the child's own values, not the group's (1.0.7).
- [ ] Change a notification's recipients, subject, body or attachments and try to leave the page —
      the unsaved-changes warning appears (1.0.7).
- [ ] Rename a field's id while a condition and an email routing rule point at it — both still point
      at it after the rename and after saving (1.0.7).
- [ ] Embed the form in a normal page/post, a text widget, a block-template area, and (if available)
      a page-builder page — the form's JS/CSS load and it submits in each placement (1.0.6).
- [ ] A shortcode with a missing or invalid form id leaves the page rendering (no fatal), just
      without the form.
- [ ] The same form twice on one page: each copy submits on its own, clicking a label focuses the
      input in that copy, and server-side errors appear in the copy that was sent (1.0.7; the id
      suffixing is automated).
- [ ] Core update notices and other plugins' notices stay visible on every FormFabricator screen: in
      the notice dock on list/settings/verifier pages, floating bottom right in the form editor.

## 3. Form settings (per-form)

- [ ] reCAPTCHA with real site/secret keys: a solved CAPTCHA passes `siteverify` on the server.
- [ ] Solve the CAPTCHA, then submit with another field invalid: the error names that field, the
      widget clears itself, and solving it again and resubmitting works (1.0.7).
- [ ] With an ad blocker actually blocking the reCAPTCHA script, the visitor sees the notice and the
      button is usable (the notice itself is automated; this checks it is triggered).
- [ ] The import preview shows where the imported form's emails will go, and a title containing
      `$&` reads literally in the preview (1.0.7).
- [ ] Export a form with a SEPA mandate from a German site and import it on an English one — the
      mandate's legal text arrives unchanged (1.0.7).
- [ ] "Show button when …": the submit button appears only while the condition is met; on a form with
      several pages it stays hidden on earlier pages even while met, and on the last page while unmet,
      without needing an input event first. Editing a condition triggers the unsaved-changes warning
      (1.0.7; the server-side refusal is automated).
- [ ] FormFabricator → Settings: save twice in a row without reloading — the second save succeeds
      (1.0.7). A raw POST without the colour, layout and reCAPTCHA site key fields keeps their saved
      values.
- [ ] Access settings in two tabs: save in the first, then the second is refused as changed
      elsewhere; saving twice in one tab works (1.0.7).
- [ ] Rotate the seal key from two tabs at nearly the same moment — the second tab says another change
      is in progress (the lock and the key history are automated).
- [ ] Behind a full-page cache: resubmit via the back button and via a cached copy — refused once as a
      duplicate, and a *different* visitor on the same cached page is not refused (1.0.2; the token
      logic is automated).
- [ ] With `WP_DEBUG` off, a failed notification still writes its line to the PHP error log (what the
      line contains is automated).
- [ ] Disable every notification and save — the editor shows the "no active notification" warning
      (the refused submission is automated).
- [ ] **Trusted proxies** (administrators only): an invalid entry is refused naming it; behind a real
      proxy/CDN with its range entered, the submission limit counts visitors separately (1.0.7).
- [ ] Set `FABRICATOR_MEMORY_BUDGET_MB` to 512. An upload field set to 200 MB shows "Maximum file
      size: 104 MB", a 105 MB file is refused with the field's size message, more than 104 MB across
      fields gets "The attached files are too large in total…" (not "server busy"), and on a form
      without a PDF a single 103 MB file goes through (`upload_max_filesize`/`post_max_size` raised)
      (1.0.7).
- [ ] With real photos on a PDF form (the messages and limits are automated): a 12 MP phone photo
      appears in the PDF, and a 36 MP scan saved at 50 MB goes through.
- [ ] A TIFF on a form whose only notification attaches just the PDF: the email carries the PDF plus
      the TIFF, and the PDF lists the TIFF by name; "Attach generated PDF" alone carries the PDF plus
      documents, never image files. Lower `memory_limit` until the PDF step runs out: the visitor
      reads "The attached files are too large in total…", and a retry with smaller files works (1.0.7).

## 4. Field-by-field submission checks

For one field per palette group, submit and look at the email and the PDF as a reader would —
layout, labels, line breaks, sub-field order. The values themselves are automated; how they look is
not.

- [ ] With JavaScript disabled: Currency 12.345 is refused ("at most two decimal places") while 12.34
      and 12.5 go through, and a Number with step 1 refuses 2.5 (1.0.7; only the client side is
      automated).
- [ ] Name and Address with sub-fields on: clicking each sub-field's label focuses that input, and a
      screen reader announces each with its own label (1.0.7).
- [ ] Date with an earliest/latest date: change the field's date format afterwards — the limit still
      applies and is named in the current format (1.0.7).
- [ ] Currency, Rating, Slider on a real touch device or emulator: tap and drag work (1.0.2).
- [ ] Rating with the keyboard alone: reached by Tab, set with the arrow keys, the stars follow, and a
      required rating can be submitted without a mouse (1.0.7).
- [ ] Select: Tab reaches the dropdown exactly once, the label names it to a screen reader, and
      clicking the label opens it (1.0.7).
- [ ] Upload: a rejected `a&b.pdf` is named back as `a&b.pdf`, not `a&amp;b.pdf`; archives
      (zip/tar/gz/7z) are rejected; after a rejection (or too many files) the form carries no file at
      all; a disguised file (right extension, other content) is refused by the server; a file near
      `upload_max_filesize` does not exhaust memory (1.0.2, 1.0.6, 1.0.7).
- [ ] Signature: draw and submit — it appears in the PDF and the email; rotate a phone mid-signature and
      the drawing survives; a single tap without drawing still counts as missing (1.0.7). Known and
      accepted: there is no keyboard or screen-reader way to sign (readme.txt, "Known limitation");
      reopen only with a decision on the alternative input.
- [ ] SEPA: the IBAN input masks as you type; a single tap on the mandate's pad still counts as
      unsigned (1.0.7).
- [ ] HTML block: in the rich-text editor, save, reload and Preview; no editor styling leaks into the
      sent email (1.0.2). A logo from your Media Library, and a relative `<img src="images/logo.png">`,
      still appear in the PDF.
- [ ] Group/Section collapses and expands; Page Break navigation back and forth keeps entered data.
- [ ] Post-data: a form on a real post submits that post's title, URL, ID and author (1.0.2).

## 5. PDF generation & tamper detection

- [ ] Open a generated PDF in two readers (e.g. a browser and Acrobat): it renders, and the seal block
      is where the layout puts it.
- [ ] A value hidden from the *displayed* layout (PDF Layout editor's visibility toggle) is still
      recoverable inside the seal block — deliberate (readme.txt, "Sealed PDFs": hiding is not
      redacting), not a bug to fix.
- [ ] A sealed PDF with an LZW, RunLength or doubly compressed stream inserted: the verifier fails it
      and lists the part under PDF Objects (e.g. "Image in object 12 (800 × 600 pixels): LZWDecode"),
      while the other sections still show results (the parser's refusal is automated).
- [ ] One pathological file (e.g. a 40 MB PDF of tiny streams, or more than 50,000 objects) through
      the verifier page on a host at the plugin's memory ceiling: a normal refusal message within
      seconds and no PHP fatal in the error log (the scans' time and memory are automated).
- [ ] A very large PDF over a throttled connection, taking more than 10 minutes, does not end with "PDF
      not found or token expired" (1.0.7).
- [ ] Batch-scan several PDFs at once: throttling keeps the page responsive (1.0.3).
- [ ] An uploaded verification PDF and its extracted images leave the protected temp folder as soon as
      its check finishes; closing the tab mid-batch leaves the rest to expire (the expiry is
      automated) (1.0.7).
- [ ] After a verification, reload and use the back button — no "resend form data" prompt, and nothing
      is uploaded and checked again (1.0.7).
- [ ] PDFs generated for outgoing mail are gone from the temp folder right after the submission.
- [ ] Two PDFs verified at once from separate tabs on a small server: past the limit, one answers
      "Server busy verifying other PDFs" and retries (the slot accounting is automated).
- [ ] A PDF whose image has a transparency mask (SMask) smaller than the image: the recreated preview
      looks right and the image is not reported as a mismatch (1.0.7).
- [ ] The inline link opens a verification PDF in the browser under its sandboxing
      Content-Security-Policy header (1.0.7).

## 6. PDF Layout editor (FormFabricator → PDF Layout)

- [ ] Logo upload/change, colour, font and margin settings all show in a newly generated PDF.
- [ ] Header/footer content renders correctly across a multi-page submission.
- [ ] The concurrent-edit notice (as in section 2) appears when two admins edit the layout at once.

## 7. Access control & multisite

- [ ] Under an Editor granted only some FormFabricator capabilities, click through every screen and
      AJAX action the grants allow and confirm the rest are refused (capability registration and
      grants are automated; this checks the screens and endpoints agree with them).

## 8. Uninstall

Nothing by hand: uninstall on a single site and across a network is automated
(`tests/Integration/UninstallTest.php`).

## 9. i18n

- [ ] Site language German (`de_DE`): admin and front-end strings are translated, including
      Name/Address sub-field labels (1.0.1) and the palette group labels.

## 10. Release package sanity

Run `./build.ps1` and inspect `build/formfabricator/` (the build's own verification already fails on
dev files in the package):

- [ ] `vendor/pdfjs` is present in the staged copy.
- [ ] The mPDF font trim kept every font family the PDF Layout editor offers.
- [ ] Install the built zip on a clean WordPress site via Plugins → Add New → Upload Plugin and run the
      smoke path (sections 1, 2, 4 for one field per group, 5) against the *packaged* build —
      packaging bugs (the flat-zip separator issue before 1.0.0, the stripped-font regression in
      1.0.2) only show up in the built artifact.
