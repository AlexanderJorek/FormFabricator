=== FormFabricator ===
Contributors: alexanderjorek
Tags: forms, form builder, pdf, gdpr, sepa
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.6
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A drag-and-drop form builder that emails submissions and/or renders them to a cryptographically sealed, tamper-evident PDF.

== Description ==

FormFabricator lets you build forms with a drag-and-drop admin editor and deliver every submission by email and/or as a generated PDF — without ever writing submission data to a database table. Nothing is retained after the request finishes except what you choose to email or download.

**Field types**

* Text, textarea, email, name, phone, number
* Address, date, time, currency
* Select, radio, checkboxes (multivalue)
* File upload, signature (canvas)
* Rating, slider
* Captcha (Google reCAPTCHA), consent checkbox, GDPR checkbox
* HTML block, section/group, page break
* Hidden post-data field, website (honeypot-friendly)
* SEPA Direct Debit mandate (IBAN with masked input + checksum validation, BIC, account holder, creditor info block, signature capture)

**PDF generation & tamper detection**

Every generated PDF can be embedded with a cryptographic seal. The included PDF Verification tool re-derives the seal from an uploaded PDF and reports byte-level and content-level tampering, including incremental-update ("PDF shadow attack") detection.

**Privacy by design**

FormFabricator has no custom database tables for form entries and stores no submission data locally. All submitted data is delivered exclusively via email and/or the generated PDF, both of which are under your own server's/mailbox's control.

== Uses Third Party / External Services ==

FormFabricator's core functionality does not communicate with any external service. Two *optional* fields, only present on forms where you've explicitly added them, do:

**Google reCAPTCHA** (Captcha field)
When you configure a reCAPTCHA site key and secret key in the form settings and add a Captcha field to a form, each form submission's response token is verified server-side against:
`https://www.google.com/recaptcha/api/siteverify`
The token itself, plus the visitor's IP address (passed as reCAPTCHA's `remoteip` parameter), is sent to Google — no other submission data. This only happens if you explicitly add a Captcha field and configure the keys.
Google reCAPTCHA [Terms of Service](https://policies.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

**openiban.com IBAN/BIC lookup** (SEPA field)
If a form contains a SEPA Direct Debit field with live IBAN lookup enabled, every time a site visitor finishes typing a syntactically valid IBAN into that field, their browser sends that IBAN to this site's own admin-ajax.php endpoint, which then makes the outbound request server-side to:
`https://openiban.com/validate/`
to look up and auto-fill the matching BIC — the visitor's browser never contacts openiban.com directly, so it is this site's server IP (not the visitor's) that reaches openiban.com. This happens live on the public-facing form for every visitor who fills in the IBAN field on a form containing a SEPA field with live lookup enabled — not just in the admin editor. No other submission data is sent. openiban.com is a free lookup service built on the [MIT-licensed goiban-service](https://github.com/apilayer/goiban-service#the-mit-license-mit); it does not publish a separate terms-of-service or privacy-policy document. Its [homepage](https://openiban.com/) states its data practice directly ("No personal data is stored. No request logs are written. Everything works in memory.") and its [imprint](https://openiban.com/imprint.html) identifies the operator.

== Third-Party Libraries & Credits ==

This plugin bundles the following open-source libraries. Both are distributed under GPL-compatible
licenses (MIT / SIL OFL / CC BY 4.0 for Font Awesome; Apache License 2.0 for pdf.js); full license
text ships alongside each in the plugin package.

**Font Awesome Free** (icons) — [fontawesome.com](https://fontawesome.com/), source and build tools
at [github.com/FortAwesome/Font-Awesome](https://github.com/FortAwesome/Font-Awesome). The bundled
CSS is the project's own minified distribution build; unminified source is published in that
repository.

**pdf.js** (PDF rendering on the verification page) — a Mozilla project,
[github.com/mozilla/pdf.js](https://github.com/mozilla/pdf.js), vendored from the official
`pdfjs-dist` npm package.

== Installation ==

1. Upload the `formfabricator` folder to `/wp-content/plugins/`, or install the plugin zip through the WordPress admin (Plugins → Add New → Upload Plugin).
2. Activate the plugin through the "Plugins" menu in WordPress.
3. On activation you'll be redirected to **FormFabricator → Settings** to complete a one-time PDF seal key setup. This is required before you can create forms.
4. Once setup is complete, go to **FormFabricator** in the admin menu to create your first form.
5. Insert the form into a page or post with the provided shortcode, shown on the form's edit screen.

== Frequently Asked Questions ==

= Where is submitted form data stored? =

Nowhere, by design. FormFabricator has no database table for submissions. Each submission is processed in-memory for the duration of the request and delivered only via email and/or a generated PDF, then discarded.

= Does FormFabricator send data to any external service? =

Only if a form uses the Captcha field (Google reCAPTCHA) or the SEPA Direct Debit field (openiban.com, called live for every IBAN entered on the public form). See "Uses Third Party / External Services" above. No other part of the plugin makes external requests.

= Why am I asked to complete a setup step right after activating? =

FormFabricator generates a cryptographic seal key used to make generated PDFs tamper-evident. This one-time setup must be completed before any forms can be created, so the seal key exists from the start rather than being added retroactively.

= Can I customize the generated PDF layout? =

Yes — the PDF Layout Editor (under FormFabricator → PDF Layout) lets you configure the logo, colors, fonts, margins, and header/footer content used when rendering submissions to PDF.

== Changelog ==

= 1.0.6 =
* Security: PDF seal-key rotation now generates a fully random key instead of deriving it from a password, closing a theoretical offline brute-force path. Please back up your key file after updating (Settings → PDF Seal Key) — existing PDFs remain fully verifiable.
* Fixed: CC/BCC recipients on notification emails were saved but never actually sent.
* Fixed: using a form field as the notification recipient, reply-to, sender, or subject could produce a broken, undeliverable address.
* Fixed: forms placed in a widget, page builder, block template, or theme template could fail to load their scripts and be unable to submit.
* Fixed: a shortcode without an explicit form ID could crash the whole page instead of simply showing nothing.
* Fixed: various conditional-logic, file-upload, GDPR-checkbox, and per-user access-control bugs.
* Fixed: deleting the plugin on a multisite network now cleans up every site, not just one.
* Improved: memory usage during large submissions now scales with the upload size and your server's actual capacity, instead of a fixed limit.
* Smaller, more secure release package — development-only files removed, more admin text translatable.

= 1.0.5 =
* Compliance: removed all remaining HEREDOC/NOWDOC syntax from field code (a WordPress.org hosting requirement); larger embedded field scripts/styles moved to proper, lintable .js/.css files.
* Compliance: normalized the license identifier to the standard SPDX format and added a "Third-Party Libraries & Credits" section documenting the bundled Font Awesome and pdf.js libraries and their licenses.
* Fixed: the German translation file is no longer bundled inside the plugin's own folder — it's now loaded from WordPress's standard language-pack location, matching how WordPress.org distributes plugin translations.
* Security: added a structural safeguard ensuring form field data can never be read from a submission before its security nonce has been verified.
* Updated the openiban.com (SEPA field) privacy disclosure with more specific sourcing for its no-logging/no-storage data practice.
* Internal: extensive code comment cleanup for maintainability; no functional changes.

= 1.0.4 =
* Internal: renamed the plugin's internal code identifiers (PHP namespace, constants, hook/option prefixes) from Forge/FormForge to FormFabricator, completing the rename started in earlier versions. No action needed — this is naming-only and doesn't change any stored data, settings, or behavior.

= 1.0.3 =
* Fixed: the PDF verification page could pile up dozens of simultaneous downloads/checks during a large batch scan, slowing or stalling the server; scans are now throttled to a few files at a time with clear "waiting…" status messages.
* Improved: admin pages now load their scripts and styles as proper, cacheable files instead of inline code on the page, for faster admin page loads and better compatibility with other plugins/security scanners.
* Hardening: re-audited every security-suppressed code line in the plugin (~120 sites) and corrected two inaccurate internal code comments found in the process; no actual issues found.
* Internal: corrected remaining stale plugin-name references in build tooling and stylesheets (cosmetic only, no functional change).

= 1.0.2 =
* Security: updated the bundled PDF-viewer library (pdf.js) to the latest version, closing a known vulnerability in PDF handling on the verification page.
* Security: hardened the temp-file handling for large email attachments and tightened permission checks on the "editing lock" beacon.
* Security: removed archive file uploads (zip/tar/gz/7z) from the Upload field per WordPress.org review, and blocked those file types outright.
* Fixed: PDF generation could fail ("Cannot find TTF font file...") for certain form content because required font files were being stripped during packaging.
* Fixed: the "Post data" field (title/URL/ID/author) always submitted blank values instead of the actual post information.
* Fixed: two admins editing the same form, settings, or PDF layout at the same time could silently overwrite each other's changes; you'll now see a warning and a "currently being edited by" notice, which also clears promptly when the other tab is closed.
* Fixed: resubmitting a form via the browser's back button, or a cached page, could be wrongly rejected as a duplicate for some visitors (or, in rare cases, wrongly accepted as a duplicate) when the site uses full-page caching.
* Fixed: the form slider field was hard to use by touch/drag on mobile devices.
* Fixed: a blocked or missing reCAPTCHA script (common with ad blockers) left the CAPTCHA button permanently disabled with no explanation; visitors now see a clear message instead.
* New: HTML block fields now have a "Show in mail/PDF" toggle to control whether their content appears in the notification email and generated PDF.
* Fixed: several bugs in the HTML block/notification email rich-text editor that could corrupt saved content, leak internal editor styling into sent emails, or cause the Preview button to fail with a generic "Network error."
* Renamed the plugin from FormForge to FormFabricator (WordPress.org trademark requirement); no action needed, your forms and settings are unaffected.

= 1.0.1 =
* Fixed conditional logic not working for checkbox (multivalue) fields.
* Fixed two fields that were arranged side by side in the builder dropping to a stacked, full-width layout on the front end once a conditional logic rule made them visible.
* Translated the Name and Address field sub-field labels in the form builder.
* Smaller plugin package: removed unused developer files bundled inside third-party libraries.

= 1.0.0 =
* Initial public release.
