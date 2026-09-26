# FormFabricator — Manual Verification Guide

Dev-only. Not shipped in the release package (excluded in `build.ps1`, same as `CLAUDE.md`).
This is a manual QA checklist for confirming a build works end-to-end before a release — it does
not replace `vendor/bin/phpcs`, `vendor/bin/phpcs --standard=.phpcs-security.xml` (its errors;
its warnings are review material), `php languages/make-pot.php --check`, or `composer audit`, all of
which run automatically as release gates inside `build.ps1`.

Test on a real WordPress install (PHP 8.1+, WP 6.5+), not just `php -l`. Use a fresh test form
for each section unless noted otherwise, and check both the admin screen and the resulting email/
PDF for every field test — a field can look right in the builder and still submit wrong.

## 0. Environment matrix

- [ ] PHP 8.1 and at least one newer PHP (8.2/8.3) if available.
- [ ] At least one **non-Windows** host (Linux/Nginx or Linux/Apache) — 1.0.7's changelog names a
      Windows-specific upload/PDF-check regression, so don't certify a release on Windows alone.
- [ ] Multisite network activate/deactivate/uninstall, in addition to single-site.
- [ ] A real outgoing mail transport (SMTP plugin or real `wp_mail`), not just `WP_Mail_Log` —
      confirm mail actually arrives, since header/recipient bugs (CC/BCC, field-as-recipient) have
      shipped silently before.

## 1. First-run setup

- [ ] Fresh activation on a site with no prior FormFabricator data redirects to
      **FormFabricator → Settings** and blocks form creation until the one-time PDF seal key setup
      is completed.
- [ ] Seal key generation succeeds and **FormFabricator → Settings → PDF Seal Key** shows a way to
      back up/download the key.
- [ ] Deleting or corrupting the seal key (the `fabricator_forms_seal_key` option in `wp_options`;
      there is no key file) shows an admin notice on the next admin load, and a form with a sealed
      PDF answers visitors with a retry error, instead of a replacement key being generated silently
      (1.0.7 behavior). "Rotate PDF key" under Settings → Security makes submissions work again.
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
      Layout, System (see field list in section 4).
- [ ] Reorder fields via drag; confirm order persists after save and reload.
- [ ] Two side-by-side fields render side by side on the front end, and stay that way once a
      conditional-logic rule makes them visible (regression check, was 1.0.1).
- [ ] Delete a field, save, reload — confirm it's gone from both the builder and the front-end
      shortcode output.
- [ ] Click a page break in the builder — confirm a settings panel opens and the Back and Next button
      labels can be changed and take effect on the front end, that the panel has no Conditions tab,
      and that a page break still cannot be dropped inside a field group (1.0.7).
- [ ] With a screen reader, check a checkbox set, a radio set, a star rating, and a name or address
      with sub-fields switched on: the question text must be announced as the name of the group, not
      read out as a label belonging to nothing (1.0.7).
- [ ] Put two *different* forms on one page through a form selection, each with a field of the same
      id (e.g. `email`) — confirm clicking a question focuses the answer in its own form, and that
      each form submits its own values (1.0.7).
- [ ] Conditional logic: direct field condition and group/section condition, each in both
      "show if" and "hide if" directions.
- [ ] A field hidden by a condition (direct or via a hidden group) is never required, never
      validated, and never appears in the email/PDF for that submission — check this explicitly
      for Captcha, GDPR checkbox, and Consent, since those are handled specially and it's by
      design, not an opt-out to add.
- [ ] Two admins editing the same form concurrently: opening the same form in a second browser/tab
      shows the "currently being edited by" lock notice, and the notice clears promptly after the
      first tab is closed (1.0.2 concurrent-edit lock). With the notice dismissed, save from both
      tabs at nearly the same moment — confirm one save is refused rather than both being accepted
      and one silently lost (1.0.7).
- [ ] Edit a field inside a group: change a setting that reveals other settings (e.g. the Address
      field's "Sub-fields" switch) and confirm the panel follows the child's own values, not the
      group's (1.0.7).
- [ ] Change a notification's recipients, subject, body or attachments and try to leave the page —
      confirm the unsaved-changes warning appears (1.0.7).
- [ ] Rename a field's id while a condition and an email routing rule point at it — confirm both
      still point at that field after the rename and after saving (1.0.7).
- [ ] Embed the form via its shortcode in: a normal page/post, a text widget, a block-template
      area, and (if a page builder is available) a page-builder-rendered page — confirm the form's
      JS/CSS load and the form submits in each placement (1.0.6 regression).
- [ ] Load a shortcode with a missing/invalid form ID — confirm the page still renders (no fatal),
      just without the form.
- [ ] Place the same form twice on one page: each copy submits on its own, clicking a label focuses
      the input in that same copy, and server-side field errors appear in the copy that was sent
      (1.0.7: later copies get an id suffix).
- [ ] Core update notices and other plugins' notices stay visible on every FormFabricator screen:
      in the notice dock on list/settings/verifier pages, floating bottom right in the form editor.

## 3. Form settings (per-form)

- [ ] Notification email: To, CC, and BCC recipients — confirm all three actually receive mail,
      not just To (1.0.6 regression: CC/BCC were saved but not sent).
- [ ] Use a form field's placeholder (e.g. `{field_id}`) in To/CC/BCC, Reply-To, From Name, and
      Subject — confirm the placeholder resolves to the submitted value and produces a valid,
      deliverable address/header rather than a broken one (1.0.6 regression). This is an
      intentional feature (dynamic routing), not an open-relay bug — see
      `[[feedback_mail_placeholder_recipients_intended]]`.
- [ ] Leave a placeholder field blank on submission — confirm the mail still sends with a sane
      fallback rather than failing silently.
- [ ] reCAPTCHA site/secret key configuration; submit with a valid token and confirm server-side
      verification against `siteverify` passes.
- [ ] Solve the CAPTCHA, then submit with another field left invalid — confirm the error names that
      field, the CAPTCHA widget clears itself, and solving it again and resubmitting works instead
      of repeating "Please confirm the CAPTCHA" (1.0.7).
- [ ] Block or remove the reCAPTCHA script (simulate an ad blocker) — confirm the visitor sees a
      clear message instead of a permanently disabled submit button (1.0.2 regression).
- [ ] Before importing a form, confirm the import preview shows where its emails will go (1.0.7).
- [ ] Import a form whose field settings hold a list where a single value belongs (e.g. a number
      field's `"min": ["a","b"]`, a rating's `"custom_icon_url": []`) — confirm the import succeeds
      and every page showing the form still loads, with those settings back at their defaults (1.0.7).
      Give the form a title containing `$&` and confirm the preview reads it literally (1.0.7).
- [ ] Export a form with a SEPA mandate from a German site and import it on an English one —
      confirm the mandate's legal text arrives unchanged instead of switching language (1.0.7).
- [ ] Routing rules: with a rule "field is empty", submit once with that field blank and once
      filled — confirm only the blank one routes there, including when a visitor types the literal
      text "[No entry]" into it. With "greater than" on an amount field, confirm 20,00 € matches a
      rule of 10. With a dropdown inside a field group, confirm a rule on it matches (1.0.7).
- [ ] Form settings → button: add a "Show button when …" condition — confirm the submit button
      only appears when it is met, and that posting the form anyway (e.g. by pressing Enter in a
      text field, or with the browser's developer tools) is refused (1.0.7). On a form with several
      pages, confirm the button stays hidden on the earlier pages even while the condition is met,
      and stays hidden on the last page while it isn't, without needing an input event first
      (1.0.7). Edit a condition and try to leave the page — the unsaved-changes warning appears.
- [ ] FormFabricator → Settings: save twice in a row without reloading — confirm the second save
      succeeds instead of "changed elsewhere" (1.0.7). Send a save without the colour, layout and reCAPTCHA
      site key fields (a raw POST) — confirm they keep their saved values instead of being reset.
- [ ] Open the access settings in two tabs, change and save in the first, then in the second —
      confirm the second is refused as changed elsewhere, and that saving twice in one tab works (1.0.7).
- [ ] Rotate the seal key from two tabs at nearly the same moment — confirm one succeeds, the other
      says another change is in progress, and that the key history lists every retired key (1.0.7).
- [ ] Load a form page many times and watch `wp_options` — confirm no `fabricator_rl_token_…` rows
      appear. Sending the form still stops after 10 sends in 5 minutes from one address (1.0.7).
- [ ] Duplicate-submission protection: submit, then resubmit via browser back button and via a
      cached copy of the page — confirm it's rejected once as a duplicate and not wrongly
      accepted twice, and (separately) not wrongly rejected for a *different* visitor sharing a
      cached page (1.0.2 regression, full-page-cache interaction).
- [ ] Trigger a mail-send failure (e.g. invalid SMTP config) — confirm the visitor sees an error
      and can retry, instead of a false "Thank you" (1.0.7 regression).
- [ ] With two notifications, make only one fail (e.g. a `pre_wp_mail` filter in a mu-plugin that
      returns false for one recipient) — confirm the visitor sees the error, and that the PHP error
      log names the form and the notification, but no email address, even with WP_DEBUG off
      (1.0.7).
- [ ] Disable every notification of a form and save — confirm the editor shows the "no active
      notification" warning after saving, and a submission is refused with an error instead of
      being accepted and silently discarded (1.0.7).
- [ ] FormFabricator → Settings → **Trusted proxies** (administrators only): enter an invalid entry
      and confirm the save is refused naming it; behind a real proxy/CDN, enter its range and
      confirm the submission limit counts visitors separately instead of everyone behind the
      proxy together (1.0.7).
- [ ] Set `FABRICATOR_MEMORY_BUDGET_MB` to 512. An upload field set to 200 MB shows "Maximum file
      size: 104 MB", and a 105 MB file is refused with the field's size message. More than 104 MB
      across several fields gets "The attached files are too large in total…", not "server busy".
      On a form without a PDF, a single 103 MB file goes through (PHP's `upload_max_filesize` and
      `post_max_size` raised to match) (1.0.7).
- [ ] On a form that attaches the PDF, with the 512 MB budget: a 50 MP photo, and a few-KB PNG whose
      header claims huge dimensions, are refused at their field with '"<name>" has 50.0 megapixels
      (12.4 MB), but images can have at most 44.7 megapixels…'. A 36 MP scan of 103 MB is refused
      with 'All files together are 103.0 MB, but with "<name>" they can be at most 52.5 MB…', and
      the same scan saved at 50 MB goes through. A 12 MP phone photo goes into the PDF.
- [ ] Upload a TIFF image on a form whose only notification attaches just the PDF — confirm the
      email carries the PDF plus the TIFF file, and the PDF lists the TIFF by name (1.0.7). A notification with only "Attach generated PDF" carries
      the PDF plus any documents, never image files. Lower `memory_limit` until the PDF step itself
      runs out of memory: the visitor reads "The attached files are too large in total…", and
      sending again with smaller files works (1.0.7).

## 4. Field-by-field submission checks

For each field: place on a form, submit valid data, submit invalid/empty data against its
required/validation rules, and confirm the value round-trips correctly into both the notification
email and the generated PDF.

**Input**: Text, Textarea, Email (format validation), Phone, Number (step-validated — with step
`1`, decimal input must be rejected; 1.0.7), Website (URL).

**Choice**: Select, Radio, Checkbox/multivalue (confirm conditional logic works for this field
type specifically — 1.0.1 regression).

**Personal**: Name (sub-field labels, incl. German), Address (sub-field labels, incl. German),
Date (test at least two date formats, e.g. `31.12.2026` and `12/31/2026` — 1.0.7 new feature),
Time.
- Address in its simple form (the "Sub-fields" switch off): submit a one-line address and confirm
  it reaches the email and the PDF as typed, not as "[No entry]" (1.0.7).
- Name and Address with sub-fields on: click each sub-field's label and confirm it focuses that
  input; with a screen reader, confirm each one is announced with its own label (1.0.7).
- Date with an earliest/latest date set: change the field's date format afterwards and confirm the
  limit still applies and is named in the current format (1.0.7).

**Advanced**:
- Currency, Rating, Slider — touch/drag usability on an actual mobile device or emulator (1.0.2
  regression), and step-validated input matching the Number field's rule (1.0.7).
- Currency — enter 12.345 and confirm it is refused with "at most two decimal places", on the page
  and again with JavaScript disabled for the check; 12.34 and 12.5 go through (1.0.7).
- Rating — reach it with the Tab key alone, set a value with the arrow keys, and confirm the stars
  follow and a required rating can be submitted without a mouse (1.0.7).
- Select — confirm Tab reaches the dropdown exactly once (no invisible extra stop before it), the
  label names it to a screen reader, and clicking the label opens it (1.0.7).
- Upload — a file named `a&b.pdf` that is rejected (wrong type, or too large) must be named back to
  the visitor as `a&b.pdf`, not `a&amp;b.pdf` (1.0.7).
- Upload — valid file types accept; blocked types (zip/tar/gz/7z and other archives) are rejected
  outright (1.0.2 hardening), and after that rejection the form carries no file at all rather than
  the rejected one (1.0.7); selecting more files than allowed behaves the same way; a disguised file (correct extension, mismatched real content) is
  rejected server-side, not just by extension check; a large upload does not blow memory (1.0.6:
  memory should scale with upload size and server capacity, not a fixed ceiling — try a file near
  your `upload_max_filesize`).
- Signature (canvas) — draw, submit, confirm it appears in PDF/email; rotate a phone
  mid-signature and confirm the drawn signature is *not* lost (1.0.7 regression). Tap the pad once
  without drawing and confirm a required signature is still reported as missing (1.0.7).
  Known and accepted: the pad needs a pointing device — there is no keyboard or screen-reader way to
  produce a signature, so a form with a required signature cannot be completed without one. Stated in
  readme.txt ("Known limitation"). Don't file it as a bug; reopen only with a decision on what the
  alternative input should be.
- SEPA Direct Debit — tap the mandate's signature pad once without drawing and confirm the mandate
  is still reported as unsigned (1.0.7); IBAN masked input + checksum validation (valid and invalid IBAN), BIC,
  Kontoinhaber, static creditor info block renders, signature capture; confirm **no outbound
  network request** is made for IBAN validation (1.0.7: openiban.com dependency was removed —
  this must stay pure client/server-side, do not re-add a lookup).

**Layout**: HTML block (confirm the "Show in mail/PDF" toggle actually includes/excludes the
block's content — 1.0.2 feature; also exercise the rich-text editor: save, reload, Preview button,
and confirm no editor styling leaks into the sent email — 1.0.2 regression class), Group/Section
(collapses/expands, conditional logic as a group), Page Break (multi-page form navigation,
back/forward preserves entered data).

- HTML block, SSRF: author a block whose CSS is `background:url(http://169.254.169.254/latest/x(y)`,
  another with `background:url(http://169.254.169.254/latest/x` (no closing bracket), and a third
  written as `style="background:url(&quot;http://169.254.169.254/&quot;)"`. Submit a form that
  builds a PDF and confirm the server makes no outbound request for any of them — watch the
  network, or point the address at a host you control and check its log (1.0.7). Repeat with
  `<img src="http://169.254.169.254/x?a='">`. A logo from your own Media Library must still appear,
  and so must a relative `<img src="images/logo.png">` next to the page.

**System**: Consent checkbox, GDPR checkbox (required-when-visible, never required when hidden —
see conditions note in section 2), Captcha (see section 3), Post-data (hidden field — confirm it
actually submits the real post title/URL/ID/author, not blank — 1.0.2 regression).

## 5. PDF generation & tamper detection

- [ ] Generate a sealed PDF from a submission; confirm the seal block is present.
- [ ] Run the sealed PDF through **FormFabricator → PDF Verification** — confirm it verifies
      clean.
- [ ] Tamper with the PDF at the byte level (edit a text string with a hex/PDF editor) and
      re-verify — confirm tampering is detected.
- [ ] Tamper via an **incremental update** (append a revision rather than editing in place — a
      "PDF shadow attack") and re-verify — confirm this is detected too, not just direct edits.
- [ ] Confirm a value hidden from the *displayed* PDF layout (via the PDF Layout editor's
      visibility toggle) is still present and recoverable inside the seal block — this is
      documented, deliberate behavior (readme.txt, "Sealed PDFs": hiding is not redacting), not a
      bug to fix.
- [ ] Insert a stream compressed with LZWDecode or RunLengthDecode, or compressed twice, into a
      sealed PDF and verify it — confirm the check finishes within seconds, fails, and lists the
      part under PDF Objects (e.g. "Image in object 12 (800 × 600 pixels): LZWDecode"), while the
      other sections still show their results (1.0.7).
- [ ] Verify a very large PDF on its own over a throttled connection, so downloading and reading it
      takes more than 10 minutes — confirm it doesn't end with "PDF not found or token expired"
      (1.0.7).
- [ ] Feed the verifier a PDF FormFabricator never generated (e.g. an arbitrary hand-made PDF) —
      confirm it's reported "Not Verifiable" (1.0.7 behavior), not a false pass or a crash.
- [ ] Batch-scan several PDFs at once on the verification page — confirm throttling keeps it
      responsive rather than piling up simultaneous downloads (1.0.3 regression).
- [ ] Confirm an uploaded verification PDF, and the images taken from it, leave the protected temp
      folder as soon as its check finishes. Close the tab in the middle of a batch: the remaining
      copies must be gone about 10 minutes later, with WP-Cron disabled after the next page view.
      A batch that takes longer than 10 minutes must still finish (1.0.7).
- [ ] After a verification finishes, reload the page and use the browser's back button — confirm
      no "resend form data" prompt appears and the PDFs are not uploaded and checked again (1.0.7).
- [ ] Confirm PDFs generated for outgoing mail are deleted after sending (check the temp folder
      immediately after a submission completes).
- [ ] Build a PDF containing many "stream" keywords with no matching "endstream" (a few hundred KB
      is enough) and verify it — confirm the check returns in seconds rather than minutes, and that
      a 10 MB file of the same shape finishes with a normal refusal rather than a PHP memory
      fatal — watch the error log (1.0.7).
- [ ] Same again with a 40 MB PDF made of many small, well-formed streams (a `stream`/`endstream`
      pair every few dozen bytes) — the other shape of the same problem. Confirm it ends in a
      refusal, not a memory fatal, on a host at the plugin's own memory ceiling (1.0.7).
- [ ] Upload a PDF with more than 50,000 object headers (e.g. `1 0 obj<<>>endobj` repeated) —
      confirm it is refused within seconds with "…has far more parts than any document this plugin
      creates…", before any parsing, and that the error log names the object count (1.0.7).
- [ ] Upload a PDF where `>>` sits before `stream` but no `<<` follows anywhere, a few MB in size —
      confirm the check finishes in seconds (1.0.7).
- [ ] Verify two PDFs at once from separate tabs while the server is small: every check now takes a
      slot, so past the limit one answers "Server busy verifying other PDFs" and retries (1.0.7).
- [ ] Verify a PDF whose image carries a transparency mask (SMask) smaller than the image itself —
      confirm the recreated preview looks right and the image is not reported as a mismatch (1.0.7).
- [ ] Open a verification PDF in the browser (the inline link) — confirm it still displays, now
      that it is served with a sandboxing Content-Security-Policy header (1.0.7).

## 6. PDF Layout editor (FormFabricator → PDF Layout)

- [ ] Logo upload/change, color, font, and margin settings all reflect in a newly generated PDF.
- [ ] Header/footer content customization renders correctly across a multi-page submission.
- [ ] Concurrent-edit lock notice (same mechanism as section 2) appears when two admins edit the
      PDF layout at once.

## 7. Access control & multisite

- [ ] Per-user/role access control: confirm a user without the relevant capability cannot reach
      `fabricator-forms*` admin pages, form data, or the PDF Verification tool.
- [ ] Confirm the various conditional-logic/GDPR/file-upload/access-control fixes from 1.0.6 don't
      regress under a non-admin role specifically. The admin pages are registered with
      `fabricator_access_*` capabilities that `Plugin::grantAccessCaps()` derives from the access
      settings, so a Subscriber opening a page URL directly must be refused by WordPress itself.
- [ ] Multisite: deleting the plugin network-wide cleans up every site's data, not just the main
      site or the one it was last active on (1.0.6 regression — was single-site-only cleanup).

## 8. Uninstall

- [ ] Deactivate, then delete via Plugins screen — confirm `uninstall.php` removes plugin options/
      seal key material as expected and leaves no orphaned data, consistent with the "no database
      tables for submissions" privacy claim in `readme.txt`.

## 9. i18n

- [ ] Switch site language to German (`de_DE`) — confirm admin and front-end strings are
      translated, including Name/Address sub-field labels (1.0.1) and the group palette labels.
- [ ] `php languages/make-pot.php --check` passes (also enforced as a `build.ps1` release gate —
      run it manually here to catch a stale `.pot` before the gate does).

## 10. Release package sanity

Run `./build.ps1` and inspect `build/formfabricator/` (left in place after a successful build):

- [ ] No dev-only files shipped: `FieldTestPage.php`/`admin-fieldtest*`, `_ExampleField.php`,
      `languages/*.po`/`*.mo`, `languages/make-pot.php`, `languages/compile-mo.php`,
      `includes/PDF/templates/HEADER-RENDERING.md`, `fabricator-perf-debug.js`, this file
      (`TESTING.md`), `CLAUDE.md`, `.phpcs*.xml`.
- [ ] `vendor/pdfjs` present in the staged copy (manually vendored, not Composer-managed).
- [ ] mPDF font trim didn't remove a font family actually selectable in the PDF Layout editor.
- [ ] Install the built zip on a clean WordPress site via Plugins → Add New → Upload Plugin and
      re-run at least the smoke path (sections 1, 2, 4 for one field per group, 5) against the
      *packaged* build, not just the working tree — packaging bugs (the flat-zip separator issue
      fixed pre-1.0.0, the stripped-font regression in 1.0.2) only show up in the built artifact.
