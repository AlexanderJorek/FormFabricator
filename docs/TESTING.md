# FormFabricator — Manual Verification Guide

Dev-only. Not shipped in the release package (`docs/` is excluded in `tools/build-config.php`, as is `CONTRIBUTING.md`).

This checklist holds only what still needs a person: real hosts and mail servers, real browsers and
devices, a screen reader, a human eye on the PDF, and admin-screen flows. Everything else runs
automatically — see "What is automated" below — and an item moves out of this list once a test
reproduces it. Section numbers are stable (the test suites refer to them); a section with nothing
left to do by hand says so.

Test on a real WordPress install (PHP 8.1+, WP 6.5+). Use a fresh test form for each section
unless noted otherwise.

## What is automated

Release gates in the build (`tools/build.php`): `php -l`, `vendor/bin/phpcs` (PSR),
`vendor/bin/phpcs --standard=.phpcs-security.xml` (errors), `php languages/make-pot.php --check`,
`composer audit`, `composer test` (PHPUnit `unit` + `perf`), `npm test` (JS), the WordPress
integration suite on a single site and a network (against a throwaway MariaDB or a named server, `tools/testdb.php`),
the package verification step (no dev files in the zip, ALTCHA hashes in the source and in the package, font trim), and
against the plugin unpacked from the zip (§10): the integration suite's `package` group and the E2E suite (`tests/e2e/`,
Playwright) in Chromium, Firefox and WebKit, Safari's engine, on a real WordPress site served by `php -S`.

CI (`.github/workflows/tests.yml`) runs on Linux: the phpcs gates and the `.pot` check on PHP 8.3, `unit` + `perf` on
PHP 8.1–8.4 (8.1 skips `perf`), the integration suite on PHP 8.1 and 8.3, single site and network, against MySQL 8,
the JS suite on Node 22 and 24, and the E2E suite in all three browsers against the repository. `php -l`, `composer
audit` and the package build run only in the release build.

| Area | Covered by |
|---|---|
| Every field type's render / validate / map / sanitize, incl. date formats and ranges, Address one-line, IBAN/BIC checks, blocked upload extensions, HTML block "Show in mail/PDF", GDPR/Consent timestamps | `tests/Integration/Fields/FieldBehaviourTest.php` (the former WP_DEBUG field test page) |
| Label/group markup screen readers rely on; unique ids for the same form twice | `tests/Unit/Fields/FieldLabelsTest.php`, `tests/Unit/Form/UniqueIdsTest.php` |
| Import with wrong-kind settings (lists where a value belongs) still renders | `tests/Unit/Fields/ImportTypesTest.php` |
| Directly posted shapes: rating 0, a plain string in an expanded address, an untouched range, a placeholder spelled like its attribute | `tests/Unit/Fields/PostedShapesTest.php` |
| Direct debit schemes: Bacs sort code and account, ACH routing number (check digit and Federal Reserve prefix), account and type; each scheme checks, shows and is read by rules with only its own details; SEPA only from the EPC's SEPA countries, the field's filter narrowing but never widening them (browser and server); an optional mandate complete or untouched, the browser telling "untouched" apart as the server does (after the real `sanitize_text_field()`); a mandate without wording shows a notice and takes no details; the browser's rules accept exactly what the server accepts on real markup; digits-only typing; the SEPA IBAN input's mask; sort code, routing and account numbers with `inputmode="numeric"` | `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/js/direct-debit.test.js` |
| After a successful submission, and after the back button restores the page, the dropdown and a "prefill now" time field follow the reset | `tests/js/reset.test.js` |
| The builder preview gets the same field scripts and texts as a real page | `tests/Integration/Admin/PreviewTest.php` |
| Stripping iframes/objects from saved HTML stays linear when a closing tag is missing | `tests/Perf/EditorSanitizePerfTest.php` |
| A deleted form selection's ID is never handed out again; a refused or missing delete is reported | `tests/Integration/Form/FormSelectModelTest.php` |
| Routing rules: "equals" against a multi-choice answer matches each ticked option; "is empty" vs "[No entry]", 20,00 € > 10, option labels in groups | `tests/Unit/Form/RoutingRulesTest.php` |
| Post data never shows a private or password-protected post; website URLs with umlaut domains pass, invisible characters in them (bidi override, zero-width, non-breaking space) are refused, in the browser too (`tests/js/validators.test.js`) | `tests/Integration/Fields/FieldBehaviourTest.php` |
| Rule values read in the browser as WordPress's real `sanitize_text_field()` / `sanitize_textarea_field()` leave them | `tests/js/condition-parity.test.js`, `tests/js/wp-sanitize.php` |
| Every PDF object reader ends a crafted object at the same byte; a huge soft mask ends in a verdict, not a fatal | `tests/Unit/PDF/ObjectLookupTest.php`, `tests/Integration/PDF/SealRoundTripTest.php` |
| Option values never empty, never shared | `tests/Unit/Fields/ImportTypesTest.php` |
| A key backup file whose fingerprint no longer matches its key is refused on import; one without a fingerprint still imports | `tests/Integration/Admin/KeyImportTest.php` |
| Unencrypted seal keys refused while a master key is configured, also after rotation; with the master key already set before the upgrade, only the keys the admin ticks are encrypted, each listed with its 128-bit fingerprint | `tests/Unit/PDF/HashSealMasterKeyTest.php` |
| JSON in HTML attributes keeps entities (consent texts, rule values) | `tests/Integration/Form/JsonAttributeTest.php` |
| Suggested privacy text: copyable paragraphs, reCAPTCHA only when configured | `tests/Integration/PluginBootTest.php` |
| Forwarded addresses believed only from trusted proxies; trusted ranges wide enough to trust much of the internet are detected (the settings warning) | `tests/Unit/Utils/ClientIpTest.php` |
| Link and remote-resource checks fail closed when PCRE gives up | `tests/Unit/Form/LinkSafetyTest.php` |
| Direct debit mandate terms (text, note, creditor ID, reference) recorded between a start and an end entry, each scheme its own (no SEPA wording in a Bacs or ACH mandate); a drawn mandate signature reported in the mail as "[Signature present]"; the Consent record names the text the page shows; checkbox limits with real plurals and repeated values counted once | `tests/Unit/Fields/RecordedTermsTest.php`, `tests/Integration/Fields/MandateSignatureTest.php` |
| Signatures declaring a huge or unreadable size are refused; a real pad image passes | `tests/Unit/Fields/SignatureSizeTest.php` |
| The "Other" text input of radio and checkbox sets, each from its own field's script | `tests/js/other-input.test.js` |
| Client validators (incl. Currency two decimals, Number step), `validatePage`, conditions incl. checkbox `id[]`, hidden fields never validated (Captcha, GDPR, Consent), blocked-reCAPTCHA notice, back/forward-cache reset | `tests/js/*.test.js` |
| Client and server agree on every single rule (value × rule value × operator), on rules whose source has no input named after it (expanded Name/Address, Direct Debit in each scheme, range slider, Post data, Upload, CAPTCHA), and on chained conditions, where a rule tests a field that is itself hidden (reads as empty on both sides; any field order; groups, checkboxes, consent; rules that never settle) | `tests/js/condition-parity.test.js` (single rules, composite sources, cascades), `tests/Unit/Form/ConditionsTest.php` (single rules) |
| Submission end to end: To/CC/BCC, `{field}` placeholders in recipients and headers, blank placeholders, header injection, required fields, duplicate token, forged token/nonce, 10 sends per 5 min per address and form, 50 across all forms, a form field never used as the sender, no rate-limit rows from page views, mail failure and retry, one of two notifications failing (log names form and notification, no address), all notifications disabled, submit-button conditions on the server, hidden fields not mailed, a field shown because the field it tests is hidden is mailed, honeypot | `tests/Integration/Form/SubmissionTest.php` |
| Image admission and the §3 numbers for a 512 MB budget | `tests/Unit/Form/ImageAdmissionTest.php` |
| Rate limiter, one-time token, concurrency slots, option lock on real SQL | `tests/Integration/Utils/DatabaseHelpersTest.php` |
| Seal key handling: no silent key creation, damaged/undecryptable keys, rotation, retired keys, lock contention; a retired key copied back into the active record is neither used nor trusted as active; under another master key, the admin notice says the master key does not open the seal keys, rotation refuses until the admin confirms the old one lost, and the rotation dialog sends "The old master key is lost" only when ticked | `tests/Unit/PDF/HashSealTest.php`, `HashSealMasterKeyTest.php`, `tests/Integration/Admin/SealKeyCardTest.php`, `tests/js/settings.test.js` |
| On a PHP without the openssl extension: the Settings page loads, Standard storage works, and choosing encrypted storage (at setup or later) answers that the extension is needed; an encrypted site moved to such a PHP still loads its settings and says the key cannot be decrypted | `tests/Integration/Admin/SealKeyCardTest.php` |
| Submitters can't forge PDF structure: object headers inside image data are no objects for any reader (a JPEG carrying 40 fake objects, a fake catalog among them, plus "/BaseFont", "/Type /Page", "/Font <<", "/Annots", "/Root", "/XObject", "/SMask", a font descriptor and a fake xref and trailer, which the generator and verifier would otherwise read, and 50,001 object headers that would push it past the object ceiling); pdfparser refuses more cross-reference entries or sections than the ceiling allows, before it holds them; bidi controls are removed from answers before the marker check and the PDF (an answer reversed by U+202E is shown to break a genuine PDF otherwise) | `tests/Unit/PDF/InBandMarkersTest.php`, `tests/Unit/PDF/GuardedPdfParserTest.php`, `tests/Perf/PdfScanPerfTest.php`, `tests/Integration/PDF/SealRoundTripTest.php`, `tests/Integration/Form/SubmissionTest.php` |
| SEPA BIC asked for only under an IBAN from outside the EEA, in the browser as on the server; its required mark appears as such an IBAN is typed | `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/js/direct-debit.test.js` |
| Direct debit settings: General holds the mandate's wording, creditor, reference and debtor switches under section titles; every label is in Advanced and follows its scheme or switch | `tests/js/builder.test.js` |
| Direct debit mandate elements switched on (debtor address, place of signing), always recorded (date of signing) or set (creditor name and address, payment type): shown, all or nothing with the rest, named by their own labels in the browser as on the server, read by rules in page order, recorded, and verified in a real PDF | `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/Unit/Fields/RecordedTermsTest.php`, `tests/js/direct-debit.test.js`, `tests/js/condition-parity.test.js`, `tests/Integration/PDF/SealRoundTripTest.php` |
| Direct debit: a save warning names what a mandate with wording leaves out (creditor name, creditor ID, a SEPA or Bacs reference), in groups too; IBAN check digits 00, 01 and 99 refused in the browser as on the server; the generated reference and the date of signing name the site's day | `tests/Integration/Admin/FormEditorServerTest.php`, `tests/Unit/Fields/DirectDebitSchemesTest.php`, `tests/js/direct-debit.test.js`, `tests/Integration/Fields/MandateDatesTest.php` |
| Submission PDFs a request left behind over an hour ago are swept by the next submission, a fresh one is kept | `tests/Integration/Form/SubmissionTest.php` |
| Refused verifier uploads are logged by `fabricator_log_file()`, never by their names; a Consent field with its placeholder text warns on save, in groups too, and in any installed language (created in German, saved by an admin using English) | `tests/Integration/PDF/VerifierPageTest.php`, `tests/Integration/Admin/FormEditorServerTest.php` |
| A single tap on a signature pad or the mandate's pad, with a mouse or a finger, is no signature; a stroke is | `tests/js/signature.test.js` |
| Signing by typing the name instead of drawing: the switch is a button, the name input takes the focus, the name reaches the one hidden input trimmed as the server trims it, switching back drops it; a mandate signed this way is complete in the browser as on the server; recorded as text and sealed, and a PDF signed this way verifies | `tests/js/signature.test.js`, `tests/Unit/Fields/SignatureSizeTest.php`, `tests/Integration/PDF/SealRoundTripTest.php` |
| Particle background: moving and following the mouse; a still picture redrawn on resize under reduced motion, and moving again when the system setting changes; one still picture when set to Static, also once the system allows motion again; the setting saved, kept by a save without it, and handed to every page as a CSS variable | `tests/js/particles.test.js`, `tests/Integration/Admin/SettingsSaveTest.php` |
| Saving a PDF Layout that hides "Signatures & Uploads" or "Form fields" names the forms whose notifications then carry no signature; the builder's warning counts both switches | `tests/Integration/Admin/PdfLayoutSaveTest.php`, `tests/Integration/Admin/FormEditorServerTest.php` |
| Saving a PDF Layout: a header image larger than a PDF takes is refused and nothing written; one that fits is saved; an imported image is scaled down by WordPress | `tests/Integration/Admin/PdfLayoutSaveTest.php` |
| Real sealed PDF verifies, with or without images, with a SEPA or an ACH mandate, with a 48,000-character non-ASCII answer, with a JPEG signature whose comment reads "%%EOF", a seal block and "/Type /Evil", with links in an HTML block (an e-mail address becomes a mailto link) or a link whose address spells "/Annots", with a long HTML block (its seal in short lines), with a seal holding "fi"/"fl" (no ligatures), with characters no font draws (CJK and emoji as U+FFFD, Fraktur letters in plain form, "ℊ" from FreeSerif, "⌭" from Quivira, combining marks, "í", "Ŝ€"), and with "Signatures & Uploads", "Form fields" or the footer hidden; edited page, redrawn or added Form XObject, shadow-attack incremental update, a second seal block, an edited font program or image, a backdated creation date, a modification date long after the generation, a changed producer, an added XMP stream, a FreeText box repeating a sealed answer, a FreeText box without /Type named through an /Annots array object, an array holding a comment or an escaped key ("/Ann#6Fts"), a link pointing elsewhere or made visible, unknown key and foreign PDF are caught; a truncated or non-PDF file ends in a notice; rotated key still verifies | `tests/Integration/PDF/SealRoundTripTest.php`, `tests/Unit/PDF/LinkTargetTest.php`, `tests/Unit/PDF/PdfDateTest.php`, `tests/Unit/PDF/NameTokensTest.php`, `tests/Unit/PDF/NormalizeTextTest.php`, `tests/Unit/PDF/RepairSpacingTest.php` |
| No outbound request from HTML-block SSRF payloads (incl. the §4 cases) or IBAN validation; own-host and relative images kept, and an HTML block's image from the Media Library and a relative one (`images/logo.png`, read from the site as a browser would) both drawn in the PDF | `tests/Integration/PDF/OutboundRequestsTest.php`, `tests/Unit/Form/LinkSafetyTest.php` |
| Hiding is not redacting: with "Form fields" hidden from the PDF Layout, every answer is still in the seal, marked as not shown; a soft mask smaller than its image shapes the verifier's preview at its own size and is no mismatch | `tests/Integration/PDF/SealRoundTripTest.php` |
| Pathological PDFs: nested object headers with one `endobj`, a long `/Contents` list, stream keywords without `endstream`, many small streams, > 50,000 objects, `>>` without `<<`, decompression bombs, LZW/RunLength/double-compressed streams — time scaling and peak memory | `tests/Perf/*`, `tests/Unit/PDF/*` |
| Verifier temp files expire ~10 min after last use, even without WP-Cron | `tests/Unit/Utils/VerifierCleanupTest.php` |
| Admin screens registered with `fabricator_access_*` capabilities; the menu for a user allowed only one screen, and for a user allowed only the verifier, WordPress's own menu (`wp-admin/includes/menu.php`, `menu-header.php`) linking FormFabricator to the verifier, whose page opens; `create_fabricator_forms` answered for the user asked about; per-role and per-user grants; Subscriber and logged-out refused | `tests/Integration/Admin/AccessControlTest.php` |
| Uninstall removes forms, options, counters, transients, cron events, user meta and the mail-attachment copies left in the temp dir (sparing one possibly still being sent) — on every site of a network | `tests/Integration/UninstallTest.php` |
| Network activation and deactivation: sweeps scheduled on activation and on each site's first admin page; every sweep and one-off event cleared on deactivation, on every site of a network, and only on its own site otherwise; deactivation removes mail-attachment copies a killed request left, but not one still in use | `tests/Integration/ActivationTest.php` |
| First run: every FormFabricator screen, the PDF verifier included, leads to Settings until the seal key is set up; the new key offered for backup until its download is confirmed; a deleted or damaged key warns administrators and refuses sealed submissions until "Rotate PDF key" | `tests/Integration/Admin/FirstRunTest.php` |
| PDF markers typed into an answer (as typed, as entities, control-split, full-width; also in a composite field) are refused at the field; image data is left out of the verifier's raw counts and type scan, also with nested dictionaries and "endstream" inside it, in linear time | `tests/Unit/PDF/InBandMarkersTest.php`, `tests/Integration/Form/SubmissionTest.php`, `tests/Perf/PdfScanPerfTest.php` |
| A seal key record edited in the database (its "compromised" flag cleared, or copied into the active slot) is unreadable, not trusted; rotation keeps old PDFs verifying | `tests/Unit/PDF/HashSealMasterKeyTest.php` |
| Sending limits: a full IPv6 /48 writes no further rows; a honeypot hit writes none; expired rows are swept by a submission at most hourly | `tests/Integration/Form/SubmissionTest.php` |
| The mandate reference as own text ("Your membership number", shown and recorded as typed) or generated per mandate (prefix, date, random part; SEPA characters, 35 at most, a prefix's accented letters written without their accents as WordPress writes them for the site's language); the creditor and reference labels, account-type choices and signature texts as set on the field; the Reference pill in the builder | `tests/Unit/Fields/RecordedTermsTest.php`, `tests/Integration/Fields/MandateReferenceTest.php`, `tests/js/builder.test.js` |
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
| Checks the server makes without JavaScript: Currency 12.345 and Number step 1; a date limit after the date format changed, named in the new format; a disguised file or an archive refused, named back as WordPress sanitizes the name (`a&b.pdf` as `ab.pdf`), never HTML-escaped; post data from the post the form was shown on; the upload limit a 512 MB budget allows (104 MB shown, 105 MB refused, too large in total, 103 MB through); a PDF-only notification carries the PDF with documents and TIFFs but no shown images, the PDF naming the TIFF; the generated PDF and the attached copies of uploads gone when the request ends; a submission with files that runs out of memory answers "The attached files are too large in total…" (status 413, also with WordPress's fatal-error handler off) and releases its token, so the visitor can retry | `tests/Integration/Form/ServerChecksTest.php` |
| A failed notification is logged with `WP_DEBUG` off | `tests/Integration/Form/DebugOffTest.php` |
| Whole forms in the browser: the submit button's condition from the start and over several pages, page navigation keeping values, the same form twice and two forms sharing a field id each sending their own values with errors in the right copy, the CAPTCHA widget reset after a failed submission, the rating and the dropdown by keyboard | `tests/js/form-flow.test.js` |
| The verifier page: a part compressed in a way the plugin never uses is listed while the rest is checked; the images it takes out of the PDF are gone once the report holds them, the checked copy when the request ends; "busy" and "too large" answers; a file far past the object ceiling on a tight memory limit | `tests/Integration/PDF/VerifierPageTest.php` |
| The verifier's batch in the browser: each check asked for by its token alone, three checks at most, a push slot apart, "busy" waited out with a countdown, a rate limit widening the gap, refusals shown on the card | `tests/js/verification.test.js` |
| PDF Layout in the PDF: colours, margins and body size; every font the editor offers embedded, with every file the build's font trim must keep; footer and page numbers on every page; hiding the footer; a Media Library logo; a header image too large to decode left out with the PDF still made; the layout's images counted in a submission's memory estimate | `tests/Integration/PDF/PdfLayoutTest.php` |
| German translation: palette groups, Name and Address sub-field labels, front-end messages; every source string translated; the committed `.mo` compiled from the `.po` | `tests/Integration/TranslationTest.php` |
| Conditions set up in the builder's Conditions tab (a "show if" on a field, a "hide if" on a group, a rule on a choice field's option) saved, rendered and followed by the page and the server alike | `tests/js/builder-conditions.test.js` (with `tests/js/render-form.php`) |
| The PDF Layout editor in the browser: the "Footer" switch takes the footer and page numbers out of the live preview and back; the preview dates its sample as the PDF does (the site's time, its date format) and names no form ID; a save shows the server's signature warning without a reload, and a later save without one removes it | `tests/js/pdflayout.test.js` |
| The form selection list: search, "Select all" and bulk delete reach only the visible selections; a refused delete keeps its row and is reported | `tests/js/formselect.test.js` |
| Edit locks in the browser (form editor, Settings, PDF Layout): the heartbeat carries the lock, a conflict names the other administrator literally, the notice clears once a heartbeat brings no conflict (the other tab closed, the lock is this page's), closing the tab releases the lock through `sendBeacon()`; an unsaved new form holds none | `tests/js/edit-lock.test.js` |
| The verifier's upload overlay covers the content area beside the admin menu, follows scrolling, and is moved out of the verifier's stacking context while the PDFs upload | `tests/js/verifier-upload.test.js` |
| Every FormFabricator screen prints exactly one notice dock (`.wp-header-end`) and the particle background's canvas | `tests/js/admin-screens.test.js` |
| The smoke path against the release zip: first run, embedding, every field, submissions, sealed PDFs and their check, activation and uninstall, with the plugin, its libraries and its trimmed fonts loaded from the unpacked zip | the integration suite's `package` group (`tests/Integration/Support/Package.php`, `tests/Integration/PackageTest.php`), run by `tools/build.php` |
| In Chromium, Firefox and WebKit on a real site (E2E): a visitor sends a form and the mail carries the answers; a required field stops the form | `tests/e2e/specs/submission.spec.js` |
| E2E, the builder: a field of every palette group added through the "Add field" dialog; rows reordered by dragging; a row dropped on another's right half pairs them side by side, on the page too; a deleted field gone; each after save and reload; two side-by-side fields stay side by side once a condition shows them; the HTML block's rich-text editor (typed and bold text, kept after reload, in Preview, in the mail without editor styling); a notification body typed without the toolbar is saved | `tests/e2e/specs/builder.spec.js` |
| E2E, two administrators on the form editor, Settings and PDF Layout: closing the first tab sends the beacon, and the second's "being edited by" notice clears at the next heartbeat | `tests/e2e/specs/edit-lock.spec.js` |
| E2E, the visitor: a label focuses the answer in its own copy of a form shown twice; the rating by arrow keys and by tap; the slider by mouse drag and by tap; a drawn signature, the mandate's too, survives turning the device; an upload refused in the browser leaves no file and none arrives; ALTCHA's real widget solves the check and the form sends; reCAPTCHA blocked by a content blocker brings the notice and a usable button | `tests/e2e/specs/visitor.spec.js` |
| E2E, admin screens: core and plugin notices in the notice dock on every screen, floating bottom right in the form editor; the particle background moving, still under reduced motion and when set to Static; WordPress's "Copy suggested policy text" copies FormFabricator's paragraphs; the verifier's upload overlay over the content beside the menu, also scrolled; no resubmission after a verification on reload or back | `tests/e2e/specs/admin.spec.js` |

## 0. Environment matrix

- [ ] The smoke path on a real web host of each kind: Linux (Nginx or Apache) and Windows (IIS, or Apache on
      Windows). CI and the release build serve the E2E site with `php -S`, but no real web server, and 1.0.7 fixed
      uploads and the PDF check failing on Windows servers.
- [ ] A real outgoing mail transport (SMTP plugin or the host's mailer) — confirm mail actually
      arrives at To, CC and BCC. The tests stop at WordPress's mock mailer and the E2E site's outbox.
- [ ] Safari itself on macOS and iOS for the E2E smoke path (a form sent, the builder's drag and drop, a signature):
      the E2E suite runs WebKit, Safari's engine, but not Safari.

## 1. First-run setup

- [ ] **Settings → PDF Seal Key**: the key download dialog saves a backup file you can open (what the server hands
      over, and that it stays available until confirmed, is automated).
- [ ] Standard-mode site: paste the issued `define()` line into a real `wp-config.php` (with OPcache on) and
      confirm: the card reads "Encrypted" (the flow with the constant in place is automated).
- [ ] The notice with the server rule for the protected PDF folder on real servers: it appears on Nginx/Caddy and
      on Nginx serving static files in front of Apache (Plesk/cPanel), and not on plain Apache. What each loopback
      answer means, the caching and the fallback are automated.
- [ ] Particle background: with Windows "Animation effects" (or macOS "Reduce motion") switched off, the background
      is a still picture (the browser's reduced-motion signal and the Static setting are automated in real browsers;
      that the system setting reaches the browser is not).

## 2. Form builder (FormFabricator → New Form / Editor)

- [ ] With a screen reader, listen to a checkbox set, a radio set, a star rating, and a name or
      address with sub-fields on: the question is announced as the name of the group (1.0.7). The
      markup behind it is automated; the announcement is not.
- [ ] Embed the form in a page-builder page — the form's JS/CSS load and it submits (a post, a text widget and a
      block template are automated).

## 3. Settings, protection and submissions

- [ ] reCAPTCHA with real site/secret keys: a solved CAPTCHA passes `siteverify` on the server (the handling of
      Google's answers, and the notice when the script is blocked, are automated).
- [ ] Behind a full-page cache: resubmit via the back button and via a cached copy — refused once as a
      duplicate, and a *different* visitor on the same cached page is not refused (1.0.2; the token
      logic is automated).
- [ ] Behind a real proxy/CDN with its range entered under **Trusted proxies**, the submission limit counts visitors
      separately (1.0.7; the list itself is automated).
- [ ] A real 103 MB upload through the web server on a form without a PDF, with `upload_max_filesize` and
      `post_max_size` raised and `FABRICATOR_MEMORY_BUDGET_MB` at 512 (the limits and messages are automated).
- [ ] With real photos on a PDF form (the messages and limits are automated): a 12 MP phone photo
      appears in the PDF, and a 36 MP scan saved at 50 MB goes through.
- [ ] Lower `memory_limit` until the PDF step of a real submission with a TIFF runs out: the visitor reads "The
      attached files are too large in total…", and a retry with smaller files works (1.0.7; the answer to the
      memory error and the released token are automated, the real fatal error on a real server is not).

## 4. Field-by-field submission checks

For one field per palette group, submit and look at the email and the PDF as a reader would —
layout, labels, line breaks, sub-field order. The values themselves are automated; how they look is
not.

- [ ] Name and Address with sub-fields on: a screen reader announces each sub-field with its own label (1.0.7).
- [ ] On a real phone: drag the slider's handle with a finger, and turn the phone while signing — the signature stays
      (1.0.2, 1.0.7; a mouse drag, taps and a turned window are automated).
- [ ] Select: a screen reader names the dropdown by its label (one Tab stop, the label link and focus are automated).
- [ ] Upload: more files than `max_file_uploads` at once leaves no file in the form; a file near
      `upload_max_filesize` does not exhaust memory (1.0.6, 1.0.7; a refused file type is automated).
- [ ] Signature, by keyboard and screen reader: Tab reaches "Type your name instead", a screen reader announces it as
      a toggle (pressed or not) and the name input by its label, and a typed name signs (the switch, the focus and
      what is sent are automated).
- [ ] Direct debit: in the PDF the mandate sits in a grey box like the metadata section, headed by its title, with
      its text, creditor ID, mandate reference, account details and signature inside; in the email it starts
      with the title and text and ends with an "End of …" line. Check it reads clearly next to other fields,
      also when the box crosses a page break (1.0.8; the entries and a passing verification are automated).
- [ ] Direct debit, Bacs and ACH, on a phone: sort code, routing and account number open the number keypad
      (`inputmode="numeric"` is automated).

## 5. PDF generation & tamper detection

- [ ] Open a generated PDF in two readers (e.g. a browser and Acrobat): it renders, and the seal block
      is where the layout puts it.
- [ ] A very large PDF over a throttled connection, taking more than 10 minutes, does not end with "PDF
      not found or token expired" (1.0.7).

## 6. PDF Layout editor (FormFabricator → PDF Layout)

- [ ] Logo, colour, font and margin settings look right in a generated PDF and in the editor's live preview (that
      each reaches the PDF, the footer switch, the preview's dates and the save warning are automated).

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

Run the build (`build -y` or `./build.sh -y`). Its verification fails on dev files in the package, on a missing or
changed `vendor/altcha` file and on a trimmed font the PDF needs, and it then runs the smoke path (the integration
suite's `package` group) and the E2E suite against the plugin unpacked from the zip, with the zip's own `vendor/`.

- [ ] Install the built zip on a clean WordPress site via Plugins → Add New → Upload Plugin, activate it, and send one
      form with a PDF: WordPress's own upload and unpacking of the zip, and a real web server, are what the build
      does not reach (the flat-zip separator issue before 1.0.0 showed only there).
