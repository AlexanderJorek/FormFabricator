# FormFabricator — Manual Verification Guide

Dev-only. Not shipped in the release package (excluded in `build.ps1`, same as `CONTRIBUTING.md`).

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
and the package verification step (no dev files in the zip, pdf.js hashes in the source and in the package, font trim). CI
(`.github/workflows/tests.yml`) runs the same on PHP 8.1–8.4 and Node 22/24, with MySQL 8.

| Area | Covered by |
|---|---|
| Every field type's render / validate / map / sanitize, incl. date formats and ranges, Address one-line, IBAN/BIC checks, blocked upload extensions, HTML block "Show in mail/PDF", GDPR/Consent timestamps | `tests/Integration/Fields/FieldBehaviourTest.php` (the former WP_DEBUG field test page) |
| Label/group markup screen readers rely on; unique ids for the same form twice | `tests/Unit/Fields/FieldLabelsTest.php`, `tests/Unit/Form/UniqueIdsTest.php` |
| Import with wrong-kind settings (lists where a value belongs) still renders | `tests/Unit/Fields/ImportTypesTest.php` |
| Directly posted shapes: rating 0, a plain string in an expanded address, an untouched range, a placeholder spelled like its attribute | `tests/Unit/Fields/PostedShapesTest.php` |
| Direct debit schemes: Bacs sort code and account, ACH routing number (check digit and Federal Reserve prefix), account and type; each scheme checks, shows and is read by rules with only its own details; SEPA only from the EPC's SEPA countries, the field's filter narrowing but never widening them (browser and server); an optional mandate complete or untouched, the browser telling "untouched" apart as the server does (after the real `sanitize_text_field()`); a mandate without wording shows a notice and takes no details; the browser's rules accept exactly what the server accepts on real markup; digits-only typing | `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/js/direct-debit.test.js` |
| After a successful submission, and after the back button restores the page, the dropdown and a "prefill now" time field follow the reset | `tests/js/reset.test.js` |
| The builder preview gets the same field scripts and texts as a real page | `tests/Integration/Admin/PreviewTest.php` |
| Stripping iframes/objects from saved HTML stays linear when a closing tag is missing | `tests/Perf/EditorSanitizePerfTest.php` |
| A deleted form selection's ID is never handed out again; a refused or missing delete is reported | `tests/Integration/Form/FormSelectModelTest.php` |
| Routing rule "equals" against a multi-choice answer matches each ticked option | `tests/Unit/Form/RoutingRulesTest.php` |
| Post data never shows a private or password-protected post; website URLs with umlaut domains pass, invisible characters in them (bidi override, zero-width, non-breaking space) are refused, in the browser too (`tests/js/validators.test.js`) | `tests/Integration/Fields/FieldBehaviourTest.php` |
| Rule values read in the browser as WordPress's real `sanitize_text_field()` / `sanitize_textarea_field()` leave them | `tests/js/condition-parity.test.js`, `tests/js/wp-sanitize.php` |
| Every PDF object reader ends a crafted object at the same byte; a huge soft mask ends in a verdict, not a fatal | `tests/Unit/PDF/ObjectLookupTest.php`, `tests/Integration/PDF/SealRoundTripTest.php` |
| Option values never empty, never shared | `tests/Unit/Fields/ImportTypesTest.php` |
| A key backup file whose fingerprint no longer matches its key is refused on import; one without a fingerprint still imports | `tests/Integration/Admin/KeyImportTest.php` |
| Unencrypted seal keys refused while a master key is configured, also after rotation; with the master key already set before the upgrade, only the keys the admin ticks are encrypted, each listed with its 128-bit fingerprint | `tests/Unit/PDF/HashSealMasterKeyTest.php` |
| JSON in HTML attributes keeps entities (consent texts, rule values) | `tests/Integration/Form/JsonAttributeTest.php` |
| Suggested privacy text: copyable paragraphs, reCAPTCHA only when configured | `tests/Integration/PluginBootTest.php` |
| Forwarded addresses believed only from trusted proxies; wide trusted ranges are reported | `tests/Unit/Utils/ClientIpTest.php` |
| Link and remote-resource checks fail closed when PCRE gives up | `tests/Unit/Form/LinkSafetyTest.php` |
| Direct debit mandate terms (text, note, creditor ID, reference) recorded between a start and an end entry, each scheme its own (no SEPA wording in a Bacs or ACH mandate); the Consent record names the text the page shows; checkbox limits with real plurals and repeated values counted once | `tests/Unit/Fields/RecordedTermsTest.php` |
| Signatures declaring a huge or unreadable size are refused; a real pad image passes | `tests/Unit/Fields/SignatureSizeTest.php` |
| Trusted proxy ranges wide enough to trust much of the internet are detected (the settings warning) | `tests/Unit/Utils/ClientIpTest.php` |
| The "Other" text input of radio and checkbox sets, each from its own field's script | `tests/js/other-input.test.js` |
| Client validators (incl. Currency two decimals, Number step), `validatePage`, conditions incl. checkbox `id[]`, hidden fields never validated (Captcha, GDPR, Consent), blocked-reCAPTCHA notice, back/forward-cache reset | `tests/js/*.test.js` |
| Client and server agree on every single rule (value × rule value × operator), on rules whose source has no input named after it (expanded Name/Address, Direct Debit in each scheme, range slider, Post data, Upload, CAPTCHA), and on chained conditions, where a rule tests a field that is itself hidden (reads as empty on both sides; any field order; groups, checkboxes, consent; rules that never settle) | `tests/js/condition-parity.test.js` (single rules, composite sources, cascades), `tests/Unit/Form/ConditionsTest.php` (single rules) |
| Submission end to end: To/CC/BCC, `{field}` placeholders in recipients and headers, blank placeholders, header injection, required fields, duplicate token, forged token/nonce, 10 sends per 5 min per address and form, 50 across all forms, a form field never used as the sender, no rate-limit rows from page views, mail failure and retry, one of two notifications failing (log names form and notification, no address), all notifications disabled, submit-button conditions on the server, hidden fields not mailed, a field shown because the field it tests is hidden is mailed, honeypot | `tests/Integration/Form/SubmissionTest.php` |
| Routing rules ("is empty" vs "[No entry]", 20,00 € > 10, option labels in groups) | `tests/Unit/Form/RoutingRulesTest.php` |
| Image admission and the §3 numbers for a 512 MB budget | `tests/Unit/Form/ImageAdmissionTest.php` |
| Rate limiter, one-time token, concurrency slots, option lock on real SQL | `tests/Integration/Utils/DatabaseHelpersTest.php` |
| Seal key handling: no silent key creation, damaged/undecryptable keys, rotation, retired keys, lock contention; a retired key copied back into the active record is neither used nor trusted as active; under another master key, rotation refuses until the admin confirms the old one lost | `tests/Unit/PDF/HashSealTest.php`, `HashSealMasterKeyTest.php`, `tests/Integration/Admin/SealKeyCardTest.php` |
| Submitters can't forge PDF structure: object headers inside image data are no objects for any reader (a JPEG carrying 40 fake objects, a fake catalog among them, plus "/BaseFont", "/Type /Page", "/Font <<", "/Annots", "/Root", "/XObject", "/SMask", a font descriptor and a fake xref and trailer, which the generator and verifier would otherwise read, and 50,001 object headers that would push it past the object ceiling); pdfparser refuses more cross-reference entries or sections than the ceiling allows, before it holds them; bidi controls are removed from answers before the marker check and the PDF (an answer reversed by U+202E is shown to break a genuine PDF otherwise) | `tests/Unit/PDF/InBandMarkersTest.php`, `tests/Unit/PDF/GuardedPdfParserTest.php`, `tests/Perf/PdfScanPerfTest.php`, `tests/Integration/PDF/SealRoundTripTest.php`, `tests/Integration/Form/SubmissionTest.php` |
| SEPA BIC asked for only under an IBAN from outside the EEA, in the browser as on the server; its required mark appears as such an IBAN is typed | `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/js/direct-debit.test.js` |
| Direct debit settings: General holds the mandate's wording, creditor, reference and debtor switches under section titles; every label is in Advanced and follows its scheme or switch | `tests/js/builder.test.js` |
| Direct debit mandate elements switched on (debtor address, place of signing), always recorded (date of signing) or set (creditor name and address, payment type): shown, all or nothing with the rest, named by their own labels in the browser as on the server, read by rules in page order, recorded, and verified in a real PDF | `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/Unit/Fields/RecordedTermsTest.php`, `tests/js/direct-debit.test.js`, `tests/js/condition-parity.test.js`, `tests/Integration/PDF/SealRoundTripTest.php` |
| Direct debit: a save warning names what a mandate with wording leaves out (creditor name, creditor ID, a SEPA or Bacs reference), in groups too; IBAN check digits 00, 01 and 99 refused in the browser as on the server; the generated reference and the date of signing name the site's day | `tests/Integration/Admin/FormEditorServerTest.php`, `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/js/direct-debit.test.js`, `tests/Integration/Fields/MandateDatesTest.php` |
| Submission PDFs a request left behind over an hour ago are swept by the next submission, a fresh one is kept | `tests/Integration/Form/SubmissionTest.php` |
| Refused verifier uploads are logged by `fabricator_log_file()`, never by their names; a Consent field with its placeholder text warns on save, in groups too, and in any installed language (created in German, saved by an admin using English) | `tests/Integration/PDF/VerifierPageTest.php`, `tests/Integration/Admin/FormEditorServerTest.php` |
| Signing by typing the name instead of drawing: the switch is a button, the name input takes the focus, the name reaches the one hidden input trimmed as the server trims it, switching back drops it; a mandate signed this way is complete in the browser as on the server; recorded as text and sealed, and a PDF signed this way verifies | `tests/js/signature.test.js`, `tests/Unit/Fields/SignatureSizeTest.php`, `tests/Integration/PDF/SealRoundTripTest.php` |
| Particle background: moving and following the mouse; a still picture redrawn on resize under reduced motion, and moving again when the system setting changes; nothing drawn when switched off, the canvas staying as the background; the setting saved, kept by a save without it, and handed to every page as a CSS variable | `tests/js/particles.test.js`, `tests/Integration/Admin/SettingsSaveTest.php` |
| Saving a PDF Layout that hides "Signatures & Uploads" or "Form fields" names the forms whose notifications then carry no signature; the builder's warning counts both switches | `tests/Integration/Admin/PdfLayoutSaveTest.php`, `tests/Integration/Admin/FormEditorServerTest.php` |
| Saving a PDF Layout: a header image larger than a PDF takes is refused and nothing written; one that fits is saved; an imported image is scaled down by WordPress | `tests/Integration/Admin/PdfLayoutSaveTest.php` |
| Real sealed PDF verifies, with or without images, with a SEPA or an ACH mandate, with a 48,000-character non-ASCII answer, with a JPEG signature whose comment reads "%%EOF", a seal block and "/Type /Evil", and with "Signatures & Uploads", "Form fields" or the footer hidden; edited page, redrawn or added Form XObject, shadow-attack incremental update, unknown key and foreign PDF are caught; rotated key still verifies | `tests/Integration/PDF/SealRoundTripTest.php` |
| No outbound request from HTML-block SSRF payloads (incl. the §4 cases) or IBAN validation; own-host and relative images kept | `tests/Integration/PDF/OutboundRequestsTest.php`, `tests/Unit/Form/LinkSafetyTest.php` |
| Pathological PDFs: nested object headers with one `endobj`, a long `/Contents` list, stream keywords without `endstream`, many small streams, > 50,000 objects, `>>` without `<<`, decompression bombs, LZW/RunLength/double-compressed streams — time scaling and peak memory | `tests/Perf/*`, `tests/Unit/PDF/*` |
| Verifier temp files expire ~10 min after last use, even without WP-Cron | `tests/Unit/Utils/VerifierCleanupTest.php` |
| Admin screens registered with `fabricator_access_*` capabilities; the menu for a user allowed only one screen; `create_fabricator_forms` answered for the user asked about; per-role and per-user grants; Subscriber and logged-out refused | `tests/Integration/Admin/AccessControlTest.php` |
| Uninstall removes forms, options, counters, transients, cron events, user meta and the mail-attachment copies left in the temp dir (sparing one possibly still being sent) — on every site of a network | `tests/Integration/UninstallTest.php` |
| Network activation and deactivation: sweeps scheduled on activation and on each site's first admin page; every sweep and one-off event cleared on deactivation, on every site of a network, and only on its own site otherwise; deactivation removes mail-attachment copies a killed request left, but not one still in use | `tests/Integration/ActivationTest.php` |
| First run: every FormFabricator screen, the PDF verifier included, leads to Settings until the seal key is set up; the new key offered for backup until its download is confirmed; a deleted or damaged key warns administrators and refuses sealed submissions until "Rotate PDF key" | `tests/Integration/Admin/FirstRunTest.php` |
| PDF markers typed into an answer (as typed, as entities, control-split, full-width; also in a composite field) are refused at the field; image data is left out of the verifier's raw counts and type scan, also with nested dictionaries and "endstream" inside it, in linear time | `tests/Unit/PDF/InBandMarkersTest.php`, `tests/Integration/Form/SubmissionTest.php`, `tests/Perf/PdfScanPerfTest.php` |
| A seal key record edited in the database (its "compromised" flag cleared, or copied into the active slot) is unreadable, not trusted; rotation keeps old PDFs verifying | `tests/Unit/PDF/HashSealMasterKeyTest.php` |
| Sending limits: a full IPv6 /48 writes no further rows; a honeypot hit writes none; expired rows are swept by a submission at most hourly | `tests/Integration/Form/SubmissionTest.php` |
| The mandate reference as own text ("Your membership number", shown and recorded as typed) or generated per mandate (prefix, date, random part; SEPA characters, 35 at most); the creditor and reference labels, account-type choices and signature texts as set on the field; the Reference pill in the builder | `tests/Unit/Fields/RecordedTermsTest.php`, `tests/js/builder.test.js` |
| The save warning for notifications that carry no signature image (no PDF, no attached files, or the PDF hides signatures; also in a group) | `tests/Integration/Admin/FormEditorServerTest.php` |
| Submitted text reserves memory; a signature counts as image data | `tests/Unit/Form/ImageAdmissionTest.php` |
| An imported field type that is no string does not end the import; a group of text blocks only still reaches mail and PDF; a trashed favorite in a form selection falls back to a form that shows; .doc out of the default Documents group, the new always-blocked types | `tests/Unit/Fields/ImportTypesTest.php`, `tests/Integration/Fields/FieldBehaviourTest.php`, `tests/Integration/Form/FormSelectModelTest.php` |
| pdfparser still has the protected methods the decompression guard overrides; log lines name a visitor's file by hash and extension only | `tests/Unit/PDF/GuardedParserHooksTest.php`, `tests/Unit/Utils/LogFileNameTest.php` |
| The Security card: "Standard — unencrypted" with a `define()` line; confirming without it changes nothing; the issued line in place turns the card "Encrypted"; an existing master key lists the unencrypted keys with the fingerprints of their backup files and encrypts only the ticked ones; "Encrypted — master key missing" | `tests/Integration/Admin/SealKeyCardTest.php` |
| The uploads-probe notice: queued as a cron event, server-name fallback until it answers, only a 403 counts as protected, the file's own content as exposed, a timeout/401/404/5xx/WAF page as unknown (a day vs an hour in the transient), the probe file removed, dismissal and administrators only, on this plugin's screens and the Plugins list only | `tests/Integration/Admin/UploadsProbeTest.php` |
| Every admin AJAX action, screen and form-post handler answers exactly the permission it belongs to, for an Editor granted each one alone, a Subscriber and an administrator; a new action without a listed permission fails the test | `tests/Integration/Admin/EndpointAccessTest.php` |
| A form title with "&" is stored as typed for a user without `unfiltered_html` (a network's site admins) | `tests/Integration/Form/FormTitleTest.php` |
| Edit locks in the form editor, PDF Layout and Settings: the second administrator is told who is editing, a tab closing releases the lock (only its owner's), a save under another's lock or from a stale page is refused, also when both saves fall in the same second | `tests/Integration/Admin/EditLockTest.php` |
| Settings saves: twice without reloading, a raw POST without the colour, layout and site key fields keeps them, an invalid trusted proxy refuses the whole save naming it, the `0.0.0.0/0` warning, proxies for administrators only, the access matrix from two tabs, a seal key rotation while another runs | `tests/Integration/Admin/SettingsSaveTest.php`, `tests/js/settings.test.js` |
| Builder saves on the server: a field placeholder as sender is emptied, the "no active notification" and "mandate without wording" warnings, the page break's button labels; the import preview lists every recipient and imports nothing; a mandate exported from a German site keeps its wording on an English one | `tests/Integration/Admin/FormEditorServerTest.php` |
| The form builder in the browser: a page break's panel (no Conditions tab, Back/Next labels saved), no page break, page header or group inside a group, a group child's settings follow the child, the unsaved-changes warning for a notification's recipients, subject, attachments and rich-text body and for the submit condition, a renamed field id carried into conditions, routing rules and the submit condition, the direct debit scheme pill, the save warning, the sender placeholder warning, the notification body saved without the editor's own styling | `tests/js/builder.test.js` |
| The form list: search, "Select all" and bulk delete reach only the visible rows; the import confirmation shows the title literally (`$&`) and every recipient | `tests/js/formlist.test.js` |
| A form in a post, a text widget and a block template brings its scripts and styles; a missing or invalid form id renders nothing; two different forms in a selection sharing a field id get distinct ids and labels | `tests/Integration/Form/EmbedTest.php` |
| reCAPTCHA on the server, with siteverify stubbed: accepted, refused, minted on another host, unreachable, asked once for two CAPTCHA fields, no request without a token | `tests/Integration/Form/CaptchaTest.php` |
| ALTCHA on the server: the endpoint's signed challenges; a solved one passes once; a reused, changed, expired, re-signed with another algorithm, forged, malformed or oversized answer and a reCAPTCHA token are refused; an answer survives a submission refused at another field; on a network, an answer counts only on the site it was made for; the widget's script is loaded from the plugin | `tests/Integration/Form/AltchaTest.php` |
| ALTCHA against ALTCHA's own code: its solver finds the server's answer and its verifier accepts the server's challenge (the vendored widget is that npm release); in the browser, the widget's settings (no interaction recording), its translated texts, the required check and the reset after a failed submission | `tests/js/altcha.test.js` |
| Checks the server makes without JavaScript: Currency 12.345 and Number step 1; a date limit after the date format changed, named in the new format; a disguised file or an archive refused; post data from the post the form was shown on; the upload limit a 512 MB budget allows (104 MB shown, 105 MB refused, too large in total, 103 MB through); a PDF-only notification carries the PDF with documents and TIFFs but no shown images, the PDF naming the TIFF | `tests/Integration/Form/ServerChecksTest.php` |
| A failed notification is logged with `WP_DEBUG` off | `tests/Integration/Form/DebugOffTest.php` |
| Whole forms in the browser: the submit button's condition from the start and over several pages, page navigation keeping values, the same form twice and two forms sharing a field id each sending their own values with errors in the right copy, the CAPTCHA widget reset after a failed submission, the rating and the dropdown by keyboard | `tests/js/form-flow.test.js` |
| The SEPA IBAN input's mask; sort code, routing and account numbers with `inputmode="numeric"` | `tests/js/direct-debit.test.js` |
| The verifier page: a part compressed in a way the plugin never uses is listed while the rest is checked; the checked copy and its images are gone when the request ends; "busy" and "too large" answers; a file far past the object ceiling on a tight memory limit | `tests/Integration/PDF/VerifierPageTest.php` |
| The verifier's batch in the browser: three downloads and three checks at most, checks a push slot apart, "busy" waited out with a countdown, a rate limit widening the gap, refusals shown on the card | `tests/js/verification.test.js` |
| PDF Layout in the PDF: colours, margins and body size; every font the editor offers embedded, with every file build.ps1's font trim must keep; footer and page numbers on every page; hiding the footer; a Media Library logo; a header image too large to decode left out with the PDF still made; the layout's images counted in a submission's memory estimate | `tests/Integration/PDF/PdfLayoutTest.php` |
| German translation: palette groups, Name and Address sub-field labels, front-end messages; every source string translated; the committed `.mo` compiled from the `.po` | `tests/Integration/TranslationTest.php` |

## 0. Environment matrix

- [ ] At least one **non-Windows web host** (Linux with Nginx or Apache) for the smoke path — CI covers
      PHP on Linux, but not real HTTP uploads through a web server, and 1.0.7's changelog names a
      Windows-specific upload/PDF-check regression.
- [ ] A real outgoing mail transport (SMTP plugin or the host's mailer) — confirm mail actually
      arrives at To, CC and BCC. The tests stop at WordPress's mock mailer.

## 1. First-run setup

- [ ] **Settings → PDF Seal Key**: the key download dialog saves a backup file you can open (what the server hands
      over, and that it stays available until confirmed, is automated).
- [ ] Standard-mode site: paste the issued `define()` line into a real `wp-config.php` (with OPcache on) and
      confirm: the card reads "Encrypted" (the flow with the constant in place is automated).
- [ ] The notice with the server rule for the protected PDF folder on real servers: it appears on Nginx/Caddy and
      on Nginx serving static files in front of Apache (Plesk/cPanel), and not on plain Apache. What each loopback
      answer means, the caching and the fallback are automated.
- [ ] On a PHP without the openssl extension: the Settings page still loads, and choosing encrypted key storage
      answers that the extension is needed instead of failing (1.0.8; no test runs without openssl).
- [ ] With another master key in `wp-config.php`: the admin notice says the master key does not open the seal keys,
      and ticking "The old master key is lost" in the rotation dialog lets the rotation through (what the server
      refuses and accepts is automated).

- [ ] Particle background on every FormFabricator page (forms, editor, selections, settings, PDF layout, verification): moving with Windows "Animation effects" on, a still picture with it off, none with Settings → Editor → Particle background → Off.

## 2. Form builder (FormFabricator → New Form / Editor)

- [ ] Drag every palette group onto the canvas at least once: Input, Choice, Personal, Advanced,
      Layout, System.
- [ ] Reorder fields via drag; confirm order persists after save and reload.
- [ ] Two side-by-side fields render side by side on the front end, and stay that way once a
      conditional-logic rule makes them visible (1.0.1).
- [ ] Delete a field, save, reload — confirm it's gone from both the builder and the front end.
- [ ] With a screen reader, listen to a checkbox set, a radio set, a star rating, and a name or
      address with sub-fields on: the question is announced as the name of the group (1.0.7). The
      markup behind it is automated; the announcement is not.
- [ ] Two forms on one page (the same form twice, or two forms of a form selection sharing a field id): clicking a
      question focuses the answer in its own form (which input each label belongs to, and each copy sending its own
      values, are automated).
- [ ] Set up a direct and a group condition in the builder, each as "show if" and "hide if", and
      confirm the front end follows them (the evaluation itself is automated).
- [ ] Two admins on the same form: the "currently being edited by" notice clears promptly after the first tab
      closes, in a real browser (the lock, its release and the refused save are automated).
- [ ] Embed the form in a page-builder page — the form's JS/CSS load and it submits (a post, a text widget and a
      block template are automated).
- [ ] Core update notices and other plugins' notices stay visible on every FormFabricator screen: in
      the notice dock on list/settings/verifier pages, floating bottom right in the form editor.

## 3. Form settings (per-form)

- [ ] reCAPTCHA with real site/secret keys: a solved CAPTCHA passes `siteverify` on the server (the handling of
      Google's answers is automated).
- [ ] ALTCHA in a real browser over HTTPS: focusing the form starts the check, it ends "Verified" in the site's
      language, and the form sends (the widget's Web Workers are out of jsdom's reach; the protocol is automated).
- [ ] With an ad blocker actually blocking the reCAPTCHA script, the visitor sees the notice and the
      button is usable (the notice itself is automated; this checks it is triggered).
- [ ] Settings → Privacy → Policy Guide: "Copy suggested policy text" copies the FormFabricator paragraphs, not
      only their headings.
- [ ] Form selection list: search, tick "Select all", bulk delete: only the rows still visible go (the form list is
      automated).
- [ ] A user whose role is allowed only the PDF verifier sees the FormFabricator menu, leading to the verifier
      (that the menu is registered for them is automated; WordPress picking the verifier as the entry is not).
- [ ] Behind a full-page cache: resubmit via the back button and via a cached copy — refused once as a
      duplicate, and a *different* visitor on the same cached page is not refused (1.0.2; the token
      logic is automated).
- [ ] Behind a real proxy/CDN with its range entered under **Trusted proxies**, the submission limit counts visitors
      separately (1.0.7; the list itself is automated).
- [ ] A real 103 MB upload through the web server on a form without a PDF, with `upload_max_filesize` and
      `post_max_size` raised and `FABRICATOR_MEMORY_BUDGET_MB` at 512 (the limits and messages are automated).
- [ ] With real photos on a PDF form (the messages and limits are automated): a 12 MP phone photo
      appears in the PDF, and a 36 MP scan saved at 50 MB goes through.
- [ ] Lower `memory_limit` until the PDF step of a submission with a TIFF runs out: the visitor reads "The attached
      files are too large in total…", and a retry with smaller files works (1.0.7; which files each notification
      attaches is automated).

## 4. Field-by-field submission checks

For one field per palette group, submit and look at the email and the PDF as a reader would —
layout, labels, line breaks, sub-field order. The values themselves are automated; how they look is
not.

- [ ] Name and Address with sub-fields on: a screen reader announces each sub-field with its own label (1.0.7).
- [ ] Currency, Rating, Slider on a real touch device or emulator: tap and drag work (1.0.2).
- [ ] Rating in a real browser: Tab reaches it and the arrow keys move between the stars (the stars following the
      change and a required rating being satisfied are automated).
- [ ] Select: a screen reader names the dropdown by its label (one Tab stop, the label link and focus are automated).
- [ ] Upload: after a rejection (or too many files) the form carries no file at all; a file near
      `upload_max_filesize` does not exhaust memory (1.0.6, 1.0.7). A rejected `a&b.pdf` is named back as
      `ab.pdf` — WordPress's `sanitize_file_name()` drops the `&`, and nothing is HTML-escaped (automated in
      `ServerChecksTest`); the earlier wording here expected `a&b.pdf`.
- [ ] Signature: draw and submit — it appears in the PDF and the email; rotate a phone mid-signature and
      the drawing survives; a single tap without drawing still counts as missing (1.0.7). Known and
      accepted: there is no keyboard or screen-reader way to sign (readme.txt, "Known limitation");
      reopen only with a decision on the alternative input.
- [ ] Direct debit, SEPA: a single tap on the mandate's pad still counts as unsigned (1.0.7; the IBAN mask is
      automated).
- [ ] Direct debit: in the PDF the mandate sits in a grey box like the metadata section, headed by its title, with
      its text, creditor ID, mandate reference, account details and signature inside; in the email it starts
      with the title and text and ends with an "End of …" line. Check it reads clearly next to other fields,
      also when the box crosses a page break (1.0.8; the entries and a passing verification are automated).
- [ ] Direct debit, builder, on a phone: sort code, routing and account number open the number keypad
      (`inputmode="numeric"` and everything the scheme pill does are automated).
- [ ] HTML block: in its rich-text editor, save, reload and Preview; no editor styling leaks into the
      sent email (1.0.2; for the notification body this is automated). A logo from your Media Library, and a
      relative `<img src="images/logo.png">`, still appear in the PDF.

## 5. PDF generation & tamper detection

- [ ] Open a generated PDF in two readers (e.g. a browser and Acrobat): it renders, and the seal block
      is where the layout puts it.
- [ ] A value hidden from the *displayed* layout (PDF Layout editor's visibility toggle) is still
      recoverable inside the seal block — deliberate (readme.txt, "Sealed PDFs": hiding is not
      redacting), not a bug to fix.
- [ ] A very large PDF over a throttled connection, taking more than 10 minutes, does not end with "PDF
      not found or token expired" (1.0.7).
- [ ] After a verification, reload and use the back button — no "resend form data" prompt (the upload ending in a
      redirect is automated; what the browser does with it is not) (1.0.7).
- [ ] PDFs generated for outgoing mail are gone from the temp folder right after the submission (removed at the end
      of the request, which the tests do not reach).
- [ ] A PDF whose image has a transparency mask (SMask) smaller than the image: the recreated preview
      looks right and the image is not reported as a mismatch (1.0.7).
- [ ] The inline link opens a verification PDF in the browser under its sandboxing
      Content-Security-Policy header (1.0.7).

## 6. PDF Layout editor (FormFabricator → PDF Layout)

- [ ] Logo, colour, font and margin settings look right in a generated PDF (that each reaches the PDF is automated).
- [ ] Section switches: hiding "Footer" removes the footer and page numbers in the editor's preview (in the PDF it
      is automated).
- [ ] Saving with "Signatures & Uploads" hidden shows the warning naming the affected forms without a reload, and
      saving with the switch back on removes it (the server's answer is automated).

## 7. Access control & multisite

Nothing by hand: every screen, AJAX action and form-post handler is checked against each permission alone
(`tests/Integration/Admin/EndpointAccessTest.php`).

## 8. Uninstall

Nothing by hand: uninstall on a single site and across a network is automated
(`tests/Integration/UninstallTest.php`).

## 9. i18n

- [ ] Site language German (`de_DE`): read through the admin screens and a form — the German reads well and nothing
      is left in English (that the strings are translated and the `.mo` is current is automated).

## 10. Release package sanity

Run `./build.ps1` and inspect `build/formfabricator/` (the build's own verification already fails on
dev files in the package, on a missing or changed `vendor/pdfjs` file, and on a trimmed font the PDF needs):

- [ ] Install the built zip on a clean WordPress site via Plugins → Add New → Upload Plugin and run the
      smoke path (sections 1, 2, 4 for one field per group, 5) against the *packaged* build —
      packaging bugs (the flat-zip separator issue before 1.0.0, the stripped-font regression in
      1.0.2) only show up in the built artifact.
