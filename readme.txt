=== FormFabricator ===
Contributors: alexanderjorek
Tags: forms, form builder, pdf, gdpr, sepa
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.7
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Drag-and-drop form builder. Sends each submission by email, optionally with a sealed PDF that shows whether it was changed later.

== Description ==

Submissions are never saved in WordPress: they go out by email, optionally with a sealed PDF.

**Forms**

* Groups, multiple pages, and fields that appear depending on earlier answers
* Field rules: required, value range, text length, date range, allowed countries or email addresses
* Duplicate, export and import forms; insert them with a shortcode, or offer several in a form selection

**Field types**

Text, text area, email, website, phone, number, amount, name, address, date, time, dropdown, radio buttons, checkboxes, file upload, signature, rating, slider, CAPTCHA (Google reCAPTCHA), consent and privacy policy checkboxes (recorded with date and time), text block, field group, page break, page step bar, page details (hidden), SEPA Direct Debit mandate.

**Emails**

* Several notifications per form, each with its own recipients, sender, subject and message
* Recipients can depend on the answers, and answers can be inserted into subject and message
* Attach the PDF and uploaded files
* If an email fails, the visitor sees an error and can try again

**Sealed PDFs**

* Every PDF is sealed with a key only your site has. The PDF Verification page shows whether a PDF is authentic, what was changed, and the original answers.
* The seal carries every answer, including ones the layout hides. Hiding a field changes what the PDF shows, not what it contains, so don't treat it as a way to remove data from the document.
* Design the PDF with the layout editor: logo, fonts, colors, sections, footer.
* Back up, replace and restore the key, and optionally store it encrypted.

**Privacy and security**

* Uploads and PDFs exist only as temporary files while the emails are sent. PDFs uploaded for checking are deleted right after the check, or 10 minutes after their last use if the check doesn't finish (on a site without visitors, at the next visit).
* Besides that, only short-lived entries such as the sending-limit counter are stored, none containing anything visitors entered.
* Sending limit per IP address, no duplicate submissions, dangerous file types always refused.
* You decide which roles or users may view or edit forms, the PDF layout, the PDF check and the settings.

**Known limitation**

The signature field, and the signature in the SEPA mandate, are drawn with a mouse, finger or pen. There is no way to produce one with a keyboard alone. If a visitor who cannot use a pointing device has to reach you, offer a second form without a required signature, or another way to get in touch.

== Uses Third Party / External Services ==

**Google reCAPTCHA**: only on forms with a CAPTCHA field, once an administrator has entered the reCAPTCHA keys.

* When the visitor clicks "Load CAPTCHA", their browser loads `https://www.google.com/recaptcha/api.js`. Google receives the visitor's IP address, browser and device details, and existing Google cookies.
* On sending, once every other field is filled in correctly, your site sends the CAPTCHA response to `https://www.google.com/recaptcha/api/siteverify`. Nothing else is sent — not the visitor's IP address, and none of their answers.

Mention this in your privacy policy (example text under FormFabricator → Settings). [Terms of Service](https://policies.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

**Images from other websites**: if you enter an image's web address in the PDF layout editor, your site downloads the image once into your media library.

== Third-Party Libraries & Credits ==

License texts are included; `THIRD-PARTY-NOTICES.txt` lists every component.

* **mPDF** (GPL-2.0): creates PDFs
* **PdfParser** (LGPL-3.0): reads PDFs for checking
* **FPDI**, **DeepCopy**, **random_compat**, **Symfony mbstring polyfill**, **PSR-7 HTTP message**, **PSR-3 logging**, **mPDF PSR-7 and PSR-3 shims** (all MIT): helpers for mPDF and PdfParser
* **pdf.js** (Apache 2.0, [Mozilla](https://github.com/mozilla/pdf.js)): reads PDFs in the browser, unchanged from the official `pdfjs-dist` package
* **Font Awesome Free** (icons CC BY 4.0, fonts SIL OFL 1.1, code MIT, [fontawesome.com](https://fontawesome.com/)): icons; readable source of its compressed style file at [github.com/FortAwesome/Font-Awesome](https://github.com/FortAwesome/Font-Awesome)

== Installation ==

Requires WordPress 6.5+, PHP 8.1+ and the PHP extensions fileinfo, gd and mbstring (included by almost every host). Without fileinfo, upload fields refuse all files.

The database must be MySQL or MariaDB, which is what WordPress normally runs on. Spam limits and the safeguards against two submissions colliding are handled by the database itself, and that needs these. Test setups on SQLite, such as WordPress Playground, are not supported.

1. As an administrator, complete the one-time setup under **FormFabricator → Settings**. Keep the key backup file it gives you somewhere safe.
2. Create a form with at least one active email notification.
3. Copy its shortcode from the form list into a page or post.

If a notice says generated PDFs are not protected, add the server rule it shows, or ask your host.

== Frequently Asked Questions ==

= Where is submitted form data stored? =

Nowhere in WordPress. Submissions go out by email (and as a PDF, if chosen); temporary files are deleted after sending. Where the emails end up depends on your email setup.

= Why can't visitors send my form? =

Usually one of these: the form has no active notification; an email failed (the reason is in the server's error log); the PDF seal key is missing (administrators see a notice); JavaScript is turned off; the sending limit was reached; the attached files are too large in total for the server (visitors are told so); an ad blocker stopped the CAPTCHA.

= Why did a recipient get a submission twice? =

When one email of a submission fails, the visitor tries again and all emails are sent again. A duplicate is easier to deal with than a lost submission.

= My site runs behind a proxy or CDN such as Cloudflare. What do I need to set? =

Otherwise all visitors share the proxy's address and one sending limit. Enter the proxy's addresses or ranges (e.g. `203.0.113.10`, `198.51.100.0/24`) under **FormFabricator → Settings → Trusted proxies**, or in wp-config.php: `define('FABRICATOR_TRUSTED_PROXIES', '203.0.113.10, 198.51.100.0/24');`

= What happens to older PDFs if I replace the key or lose my server? =

Replaced keys stay on your site, so older PDFs remain checkable. After a server loss, add the old keys back from their backup files. Without a backup, those PDFs can no longer be confirmed.

= What happens when I delete the plugin? =

Deactivating keeps everything. Deleting removes all forms, settings, seal keys and stored files for good, so keep your key backup files.

== Changelog ==

= 1.0.7 =
* New: choose a date format for each date field (for example 31.12.2026 or 12/31/2026).
* New: "Show button when conditions match" now works, on every page of a multi-page form, and the server refuses a submission that doesn't meet the conditions.
* New: the Back and Next labels of a page break can be edited.
* New: a "Trusted proxies" setting for sites behind a proxy or CDN, so visitors don't all share one spam limit.
* Changed: a form needs at least one active notification before it can be sent; the form editor warns you when you save one without.
* Changed: number and slider fields only accept values that fit their step, and amount fields at most two decimal places.
* Changed: rich texts (HTML block, consent and mandate texts) no longer allow form elements such as input fields.
* Changed: on forms that create a PDF, an image too large to embed is refused with a message naming it. TIFF images are now attached next to the PDF, like documents.
* Changed: the date in the PDF footer uses your site's date format.
* Improved: if a form, email or upload can't be sent, visitors see an error and can try again, instead of a "Thank you". The reason is written to the server's error log.
* Improved: a missing or damaged PDF seal key now shows a warning, instead of being quietly replaced.
* Improved: the PDF check marks documents it cannot confirm as "Not Verifiable", deletes uploaded PDFs right after the check, and can no longer be kept busy or run out of memory by crafted files.
* Improved: stronger protection against spam, disguised file uploads and other misuse. Before importing a form, you see where its emails will go.
* Improved: keyboard and screen-reader support for star ratings, dropdowns, choice groups and the name and address fields.
* Improved: the same form can be placed twice on one page.
* Privacy: the SEPA field no longer contacts an outside service.
* Fixed: file uploads and the PDF check did not work on Windows servers.
* Fixed: email rules and show/hide rules that never matched, or that browser and server judged differently, so a visible field could be missing from the email and the PDF.
* Fixed: the simple address field lost the typed address; a tap on a signature pad stored an empty signature; a signature could vanish when a phone was rotated.
* Fixed: after a failed submission, the CAPTCHA kept saying it was unsolved. It is now checked only once the rest of the form is valid.
* Fixed: on translated sites, a failed step of the PDF check could still show a green badge.
* Fixed: saving settings, access settings, PDF layouts or forms could lose or reset values (such as the reCAPTCHA site key, the logo or backslashes), and two saves at the same moment could overwrite each other. Conflicting saves are now refused.
* Fixed: many smaller problems with emails, PDFs, file uploads, imports and the admin screens.

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
