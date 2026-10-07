# Contributing to FormFabricator

FormFabricator is a WordPress form plugin with a drag-and-drop builder, PDF generation and email delivery.

This file explains **the rules the code follows and why**. Code comments state a rule where it applies; the background
lives here. It is dev-only, like everything in `docs/` and `tools/`: the release build leaves it out of the package.

**Contents**

1. [Getting started](#getting-started)
2. [The non-negotiables](#the-non-negotiables)
3. [Directory layout](#directory-layout)
4. [Fields](#fields)
5. [Data storage](#data-storage)
6. [PDF system](#pdf-system)
7. [Coding rules](#coding-rules)
8. [Front-end assets](#front-end-assets)
9. [Tests](#tests)
10. [Security review](#security-review)
11. [Translations](#translations)

---

## Getting started

On a fresh clone, run `build -Setup` on Windows or `./build.sh --setup` on macOS and Linux (or menu item 3 of either).
It installs the Composer dev dependencies and, on Windows, downloads the throwaway test database for the integration
suite; on macOS and Linux, point `WP_TESTS_DB_*` at a database (see [Integration suite](#integration-suite)). Run
`npm install` once for the JS suite.

| What | Command |
|---|---|
| Unit + perf tests | `composer test` |
| Integration tests (real WordPress + DB) | `composer test:integration` |
| JS tests | `npm test` |
| Style linter (PSR-1/PSR-2) | `vendor/bin/phpcs` |
| Security linter | `vendor/bin/phpcs --standard=.phpcs-security.xml` |
| Regenerate the `.pot` | `php languages/make-pot.php` |
| Check the `.pot` is up to date | `php languages/make-pot.php --check` |
| Compile a `.po` to `.mo` | `php languages/compile-mo.php languages/formfabricator-de_DE.po` |
| Release build (all gates) | `build -y` (Windows), `./build.sh -y` (macOS, Linux), or `php tools/build.php` |
| Offline release build | `build -SkipAudit`, `./build.sh --skip-audit`, or `php tools/build.php --skip-audit` |

The build is PHP (`tools/build.php`), the same on every system. `build.cmd` and `build.sh` only launch it; run without
arguments, they show a menu. What the package leaves out, keeps and checks is data in `tools/build-config.php`, which
the build, `languages/make-pot.php` and the font-trim test all read.

---

## The non-negotiables

The short version. Each point links to its full explanation.

1. **Never store submission data locally.** Submissions go to email and/or PDF only. → [Data storage](#data-storage)
2. **No HEREDOC/NOWDOC, anywhere.** WordPress.org rejects plugins that use them. → [Coding rules](#coding-rules)
3. **Exception messages are constant strings.** Details go in a `fabricator_log()` line. →
   [Coding rules](#exception-messages-are-constant-strings)
4. **Field classes never call each other.** Shared helpers go in `includes/Utils`. → [Fields](#fields)
5. **A field's PDF cell holds exactly its sealed value.** No labels, no boxes. → [PDF system](#pdf-system)
6. **Every scan over uploaded PDF bytes is single-pass, and measured.** →
   [Scanning untrusted PDF bytes](#scanning-untrusted-pdf-bytes)
7. **Front-end and server condition logic must agree.** → [JS suite](#js-suite)
8. **Comments explain what and why, never history.** → [Comments](#comments)

---

## Directory layout

```
assets/                 CSS, JS front-end assets
includes/               PHP plugin source (Admin, Fields, Form, PDF, Utils)
includes/PDF/templates/ mPDF layout templates
languages/              .pot (ships), German .po/.mo and the translation tools (don't ship)
vendor/                 Composer dependencies + the manually-vendored ALTCHA widget
formfabricator.php      plugin entry point
uninstall.php           cleanup on uninstall

docs/                   CONTRIBUTING.md, TESTING.md                    (dev-only)
tests/                  the test suites and their phpunit*.xml.dist    (dev-only)
tools/                  the release build and its test database        (dev-only)
build.cmd, build.sh     launchers for tools/build.php                  (dev-only)
```

### How classes load

- Plugin classes load through Composer's PSR-4 map, in production and in the tests: `FabricatorForms\X\Y` lives in
  `includes/X/Y.php`.
- `Plugin::load()` loads only the field classes up front, because `FieldRegistry::registerDefaults()` discovers them
  among the declared classes.
- `Plugin.php` is required explicitly, because it also defines the global function `fabricator_log()`, which no
  autoloader can find.

---

## Fields

A field's slug is what its `getType()` returns. That is the authoritative name; `FieldRegistry::FIELD_MAP` only
documents it.

| Group | Slugs |
|---|---|
| Text input | `text`, `textarea`, `email`, `name`, `phone`, `number`, `website` |
| Structured input | `address`, `date`, `time`, `currency` |
| Multiple choice | `select`, `radio`, `checkbox` |
| Files and drawing | `upload`, `signature` (canvas) |
| Scales | `rating`, `slider` |
| Consent and protection | `captcha` (ALTCHA or reCAPTCHA, see below), `consent`, `gdpr` |
| Layout | `html`, `group` (section), `pagebreak`, `page-header` |
| WordPress | `postdata` |
| Payment | `directdebit` (see below) |

**Field classes don't call each other.** A helper that several fields need goes in `includes/Utils`.

### Direct Debit Mandate (`directdebit`)

A composite field: account details, account holder, a static creditor info block and a signature canvas.

- **One scheme per field**, picked by the admin:

  | Scheme | Account details |
  |---|---|
  | SEPA | masked IBAN; BIC only for IBANs from outside the EEA (`SEPA_NON_EEA`) |
  | Bacs | sort code + account number |
  | ACH | routing number + account number + account type |

- **Wording.** Each scheme has its own title, text, note and creditor settings. Every text the mandate shows is a
  setting, with the scheme's wording as the default. The builder's General tab holds what the mandate says and
  collects (`getGeneralSchema()`); every label is in the Advanced tab (`getAdvancedSchema()`), shown only while the
  scheme or switch it belongs to is on.
  - Only SEPA ships default mandate wording. Bacs and ACH texts are the creditor's to enter.
  - A mandate without wording shows a notice and takes no details.
- **Validation.** Account details are checked offline. SEPA accepts only the countries in
  `DirectDebitField::SEPA_COUNTRIES`: the IBAN codes from the EPC's list EPC409-09, currently v8.0. Update the constant
  when the EPC updates the list.
- **Optional mandates are all or nothing:** either untouched, or complete and signed.
- **Optional mandate elements.** Elements a form usually collects in its own fields (the debtor's address, the place
  of signing) are asked for inside the mandate only when switched on (`DEBTOR_EXTRAS`); once on, they belong to the
  all-or-nothing rule. The date of signing is always recorded. The creditor's name and address and the
  payment type are shown and recorded when set. `DirectDebitField::parts()` is the one list of inputs that rendering,
  checking, recording and the condition value follow.
- **Mandate reference:** either the admin's own text (such as "Your membership number") or generated per mandate
  (`ref_mode`).

### CAPTCHA (`captcha`)

One provider per field, picked by the admin:

- **ALTCHA** (the default; also what a field without the setting reads as). A proof-of-work check with no third
  party. The widget is `vendor/altcha/`, an unmodified npm release pinned by its `VERSION` file. The
  server side is `Utils\Altcha`: it signs each challenge with a secret derived from the site's salts, and checks an
  answer with two HMACs (no key is derived again). Each challenge counts once (`SingleUseToken`).
  `tests/js/altcha.test.js` checks the server against ALTCHA's own solver and verifier, from the npm dev dependency
  of the same version. Update both together.
- **reCAPTCHA v2.** The script loads from Google only after the visitor clicks; the server asks `siteverify`.

---

## Data storage

**No form submission data is ever stored locally.** All data goes to email and/or PDF only.

- There are no custom database tables for entries.
- Temporary files holding submission data are deleted as soon as they have been read. Their removal is registered the
  moment they exist (a shutdown function, which also runs after a memory or time fatal), and an hourly sweep, run from
  submissions as well as WP-Cron (`Plugin::sweepIfDue()`), clears any a killed request left behind. Deactivation ends
  that sweep, so it sweeps once more, and uninstall does the same for the system temp dir. Both leave anything changed in
  the last ten minutes: a submission may still be sending it, here or on another site sharing the temp dir.
- Log lines never carry a visitor's file name, alone or inside a path: `fabricator_log_file()` stands in for it (a short
  hash and the extension). The debug log is kept indefinitely, often in a web-readable folder.

---

## PDF system

### Overview

PDF generation lives in `includes/PDF/`.

| Part | Role |
|---|---|
| `Generator.php` | Builds the PDF with mPDF, using `templates/layout.php` |
| `HashSeal.php` | Signs (seals) each document |
| `PdfUtils.php` + `GuardedPdfParser.php` | Read a PDF back for the verification page |
| `includes/Form/MailSender.php` | Sends the mail; the generator reaches it through the `fabricator_forms_submission` action |

### How the seal is checked

The generator wraps each field's PDF cell in invisible **field markers**. The verifier compares the text between the
markers with the value stored in the seal.

- **A cell holds exactly its sealed value, nothing else.** That is `pdfData()`'s `cell_html`. Labels, titles and boxes
  go outside it: in the label row, or, for a titled box around a group of fields (like the direct debit mandate), via
  `PdfDescriptor::opensFrame()` / `closesFrame()`. `tests/Integration/PDF/SealRoundTripTest.php` verifies a real PDF
  for each case.
- **Hiding is not redacting.** The seal carries every answer, including those the PDF Layout hides ("Signatures &
  Uploads", "Form fields"), each flagged `shown` true or false. The verifier pairs only the shown answers with the
  PDF's field markers, in order. A hidden field has no markers.
- **The PDF draws exactly what the seal records.** The verifier reads the text back with pdfparser, so the generator
  writes only text that reads back as itself (`Generator::drawableText()`, applied to the values both the PDF and the
  seal are made from; the mail keeps what was sent):
  - A character no shipped font draws becomes U+FFFD, or its plain form ("𝔏" as "L") when that can be drawn.
    FreeSerif.ttf, then Quivira.otf, draw what the layout font lacks (`Generator::FALLBACK_FONTS`), but only below
    U+FFFF: beyond it, mPDF's text doesn't read back.
  - Ligatures and glyph composition are off for the whole document (`layout.php`): they read back as other characters.
  - pdfparser unescapes string bytes twice, so a character ending in byte 0x5C before one from U+2000–U+29FF gets an
    invisible U+034F between them (`separateEscapePairs()`).
  - The seal is written in lines of `SEAL_LINE_CHARS`, without ligatures.
  - The verifier compares a field's text without whitespace (`Verificationpage::comparableText()`): pdfparser loses
    a space at a line wrap and adds one at a font switch. With the intl extension, Unicode normalization also sorts
    combining marks across a lost space, so the text is normalized again once the spaces are gone. CI runs with intl,
    a local run usually without: both paths get tested.
- **One shared size cap.** Generator and verifier both use `HashSeal::MAX_SEAL_BLOCK_BYTES`, so the verifier never
  refuses a genuine PDF.
- **Names are read as spelled.** A viewer decodes `#xx` escapes in names (`/Ann#6Fts` is `/Annots`); the checks
  don't. mPDF spells every name this plugin's PDFs use plainly, so a file with an escaped name anywhere in its syntax
  fails (`PdfUtils::escapedName()`). Finding a key means finding a name token (`PdfUtils::nameTokens()`), not its
  spelling inside a string, such as a link's address.

### Submitters must not be able to forge structure

The verifier reads the document's structure from bytes that a submitter partly controls: their typed answers and their
uploaded images. Neither may carry structure.

- **Typed answers.** An answer that contains a field marker or seal delimiter, either as typed or after the verifier's
  normalization (`PdfUtils::normalizeText()`, shared by both sides), is refused at its field
  (`PdfUtils::reservedMarker()`, checked in FormProcessor).
- **Text direction.** mPDF lays text out by the Unicode bidi algorithm. The explicit controls (U+202A–U+202E,
  U+2066–U+2069) can reverse Latin text into a marker, so FormProcessor removes them from answers before the marker
  check (`PdfUtils::stripBidiControls()`). Without them, in the left-to-right paragraphs the PDF uses, no reordering
  can assemble a marker. A right-to-left PDF layout would need this argument made again.
- **Image data.** An image is the uploader's own file. Any count or scan of structure over raw bytes (`%%EOF`, seal
  blocks, `/Type`, `/BaseFont`, page objects, seal markers in streams), by the generator as by the verifier, skips
  image stream data (`PdfUtils::imageStreamSpans()`,
  `countOutsideImageData()`). Object headers inside image data are no objects: every object reader finds headers with
  `PdfUtils::finderOutsideImageData()`.

### Seal keys

**The constant decides.** When `FABRICATOR_SEAL_MASTER_KEY` is defined, every stored seal key is encrypted. Whether
encryption applies is decided by the constant, not by a storage option, because the same database write that could
plant a key could also flip an option.

- `HashSeal::decryptKey()` refuses an unencrypted key: only a direct database write can have put it there.
- Rotation retires a key exactly as stored. It never encrypts an unencrypted key into a trusted one.

**Upgrading with the constant already set.** Only the unencrypted keys the admin explicitly ticks get encrypted
(`HashSeal::unencryptedKeys()`, `encryptExistingKeys($only)`). Each key is shown by its `HashSeal::keyFingerprint()`
(128 bits; a UUID is only a label), and the key backup file carries the fingerprint too.

**Keys are bound to their record.** An encrypted key is bound to its slot, UUID, status and "compromised" flag as
AES-GCM associated data (`HashSeal::keyAad()`).

- A key moved to another slot is re-encrypted for the new one (that is what rotation does).
- An edited record decrypts to nothing, never to a trusted key.
- A retired key's old active record, copied back whole, still decrypts in the active slot. The live active key is never
  in the history, so an active UUID found there is neither used nor trusted as active (`HashSeal::isRetiredUuid()`);
  its history entry decides. Restoring the whole database restores its keys too, which no check can tell.

**The right master key.** A check value encrypted under the master key (`HashSeal::masterKeyMatches()`) tells a damaged
record from a wrong `FABRICATOR_SEAL_MASTER_KEY`. Rotation moves an unreadable record into the history as stored; under
the wrong master key that would bury a key the right one could still read, so every key write refuses
(a `\RuntimeException` with the code `HashSeal::WRONG_MASTER_KEY`) until the admin puts the right one back or confirms
it lost.

### Scanning untrusted PDF bytes

**Every scan over uploaded PDF bytes must be provably single-pass.**

#### Why

- **Time.** `strpos()` or `preg_match()` with a start offset scans to the end of the file when there is no match. Doing
  that once per object or per stream is quadratic, and the uploader decides how many objects there are.
- **Memory.** `preg_match_all()` with `PREG_OFFSET_CAPTURE` over a whole file costs several times the file's size in
  memory. That is more than `MemoryBudget` reserves, so a clean refusal turns into a PHP memory fatal.

#### Rules (in `PdfUtils` and the verifier)

1. **Only move forward.** Walk with a cursor that never goes back (`PdfUtils::nextStreamBody()` is the pattern), or
   loop `preg_match(..., $pos)` where `$pos` strictly advances.
2. **Never index every match in the file.** Yield results instead. `streamBodies()` is a `Generator` for exactly this
   reason.
3. **Latch "not found".** If a needle is absent once (no `endstream` ahead, no `endobj` ahead), it is absent for every
   later offset too. Stop instead of searching again.
4. **Read dictionary keys from the dictionary only** (the bytes before `stream`), never from the whole object. Image
   data can contain any bytes, including every key name.
5. **Measure before claiming a fix.** See [Proving a scan is fixed](#proving-a-scan-is-fixed).

#### Tools in `PdfUtils`

| Tool | Use it for |
|---|---|
| `nextAt($haystack, $needle, $offset, $cache)` | Any per-object or per-marker search, instead of a bare `strpos()`. Always returns exactly what `strpos()` would; reuses the last hit while it still lies ahead and remembers "none". |
| `objectDefinitionIndex()` + `definitionFromIndex()` | Resolving object references. Finds every object's last definition in one pass, then answers in O(1), exactly as `lastObjectDefinition()` would. One whole-file search per reference costs "references × file size", and the uploader picks the number of references. |
| `MAX_OBJECTS` + `declaredObjectCount()` | The ceiling. The verifier refuses a file above it before indexing anything, counting object headers outside image data (an uploaded image must not get a genuine PDF refused). Every index that grows with attacker-chosen counts (objects, references, names) is held to it or throws `LengthException('Too many objects…')`, which the verifier maps to its refusal message. pdfparser builds one object per cross-reference entry, wherever it points, so `GuardedRawDataParser` counts the entries of every table and xref stream as pdfparser reads them, and the sections it follows (`MAX_XREF_SECTIONS`). A PDF from this plugin holds a few thousand objects at most. |

#### Proving a scan is fixed

`tests/Perf/PdfScanPerfTest.php` feeds the scanners pathological shapes and asserts both wall time and peak memory:

- many objects with no streams
- many tiny streams
- a file of bare `stream` keywords
- no `endstream` at all

When you fix a scan, add the new shape there and **check that it fails against the unfixed code**. Time is asserted as
a growth exponent (see `tests/Support/Measure.php`), so the shape must be one where the old version really is
quadratic.

---

## Coding rules

### No HEREDOC/NOWDOC

WordPress.org rejects hosted plugins that use HEREDOC/NOWDOC syntax, because its code sniffers can't verify escaping
inside them. **This is an absolute rule, not a style preference.**

Instead:

- **Field CSS/JS goes in its own file.** That covers `getStyles()`, `getClientInit()`, and any `fn` string inside
  `getClientValidation()` / `getClientEmptyCheck()`.
  - Location: `assets/css/fields/` or `assets/js/fields/`.
  - Name: after the class, e.g. `UploadField.css`.
  - Several assets for one class (multiple validation rules, an empty-check function): add the rule or purpose as a
    suffix, e.g. `DirectDebitField.iban.js`, `DirectDebitField.debit-bic.js`.
  - Load it with `BaseField::readFieldAsset()`.
  - Bonus: real syntax highlighting and linting instead of a PHP string.
- **Only a short, genuinely one-line JS/CSS literal** may stay inline as a plain PHP string.
- **Other multi-line text that must live in PHP** (such as `SecureDir::WEB_CONFIG`) is built from concatenated strings.

### Exception messages are constant strings

The security gate treats a concatenated exception message as unescaped output. Throw with a constant message and put
the details in a `fabricator_log()` line beside the throw.

### Comments

A comment says **what the code does and why it has to be that way**. How the code used to look, which bug a line fixed
and in which version belong in the commit message, not in the code.

---

## Front-end assets

- Files in `assets/js/` are served directly. There is no build step unless one is explicitly added.
- Prefer a lightweight JS solution over a heavy framework.

### Design decisions

These are known, accepted trade-offs.

**Form selections.** A form selection (`FormSelectList`) renders every listed form (up to 200) on each page view and
shows one at a time. Switching is instant and the page stays cacheable.

**Inlined field assets.** `Assets::enqueueFront()` inlines every field type's CSS and JS into any page that renders a
form, whether or not the form uses those fields.

- Cost: 88 KB measured (24 KB across 22 CSS files, 64 KB across 29 JS files), re-sent with every page view because it
  is inline and so not cached.
- Why the JS can't simply be enqueued: the per-field JS files are function-expression fragments assembled into
  `window.FabricatorFieldInits` / `FabricatorValidators` / `FabricatorEmptyChecks`. They can't become standalone
  scripts without changing how field scripts reach the browser.
- Why the CSS can't be split off alone: styles could then arrive after `wp_head` in widget, block-template and
  page-builder placements.
- Reopen this only with a plan that covers those placements.

---

## Tests

### The suites at a glance

| Suite | Command | Loads WordPress? | Release gate in the build | CI |
|---|---|---|---|---|
| `unit` + `perf` | `composer test` | No (Brain Monkey stubs) | Yes | PHP 8.1–8.4, with the phpcs gates; 8.1 skips `perf` |
| `integration` | `composer test:integration` | Yes, real WordPress + DB | Yes, single site and multisite | Against a MySQL 8 service |
| JS | `npm test` | No (jsdom) | Yes | Own job |

The PHP 8.1 job skips `perf` because `memory_reset_peak_usage()` needs PHP 8.2+.

### Unit and perf suites

Configured in `tests/phpunit.xml.dist`, tests in `tests/`. WordPress functions are stubbed per test with Brain Monkey.
`tests/Support/FakeWordPress.php` is an in-memory options/transients/cron/`$wpdb` for the stateful helpers (HashSeal,
OptionMutex, VerifierCleanup).

**Principles**

- **Test the real code.** When the logic is a private step of a large class, call it through `Support\Reflect` rather
  than copying the regex into the test. A copied oracle keeps passing after the production code changes.
- **Pin rewrites with the old implementation.** A test that pins a rewrite keeps the old implementation as a reference
  oracle and compares both on random input (`StreamScanTest`, `ObjectIndexTest`, `VerifierScansTest`). Seed with
  `mt_srand()` so a failing case reproduces.
- **One deliberate difference, at most.** An oracle may differ from the code it pins in one deliberate way, named in the
  test's docblock and exercised by the generated input. Examples: in `StreamScanTest`, a stray `endstream` opens
  nothing; in `ObjectIndexTest`, an object ends at the next header.
- **Don't stub what needs real WordPress.** Anything that depends on `wp_kses`, `wp_mail`, capabilities, uninstall or
  multisite belongs in the integration suite, not in more stubs.

### Integration suite

Configured in `tests/phpunit-integration.xml.dist`, tests in `tests/Integration/`.

**What it runs against**

- Real WordPress from `vendor/roots/wordpress-no-content`, plus the core test library `wp-phpunit/wp-phpunit`.
- A MySQL/MariaDB database whose tables it recreates. Configure it with these environment variables:

  | Variable | Default |
  |---|---|
  | `WP_TESTS_DB_HOST` | `127.0.0.1` |
  | `WP_TESTS_DB_NAME` | `wordpress_test` |
  | `WP_TESTS_DB_USER` | `root` |
  | `WP_TESTS_DB_PASSWORD` | `root` |

- `WP_MULTISITE=1` runs it as a network.

**The database during a build** (`tools/testdb.php`):

- A server named by `WP_TESTS_DB_HOST` (and `_NAME`, `_USER`, `_PASSWORD`) is used on any system: a local MySQL or
  MariaDB, or a container (`docker run -d -p 3306:3306 -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress_test
  mariadb:11.4`, then `WP_TESTS_DB_HOST=127.0.0.1:3306`). Every table in that database is dropped.
- Otherwise, on Windows, a throwaway portable MariaDB: downloaded once (pinned SHA-256) into
  `%LOCALAPPDATA%\FormFabricator\test-db` (about 100 MB), and started only for that step.
- On macOS and Linux MariaDB publishes no portable build to pin, so a full build without a named server stops and says
  how to provide one.
- An offline build (`--skip-audit`) without a database skips the suite with a warning.

**Local PHP setup.** PHP needs `mysqli` in the child process that installs WordPress too, so enable it through
`php.ini` or `PHP_INI_SCAN_DIR`, not `-d`. The build does the latter when `php.ini` doesn't load it.

**Writing tests**

- **Base classes.** Extend `Integration\TestCase` or `Integration\AjaxTestCase`, never `WP_UnitTestCase` directly.
  WordPress's test library assumes PHPUnit 9; `Support\PhpUnit10Compat` replaces the one method that breaks on
  PHPUnit 10. The polyfills stay on 2.x, the last line that supports PHPUnit 10.
- **Submissions** go through the real AJAX action. `AjaxTestCase::submit()` posts with a nonce and a fresh one-time
  token, just as front.js does. Mail lands in WordPress's MockPHPMailer; read it with `sentMail()`.
- **Admin actions** go through `AjaxTestCase::ajax()`, which reads the *first* response sent. Several handlers wrap
  `wp_send_json_success()` in a catch-all that, under a test's throwing `wp_die()` handler, catches its own exit and
  answers a second time.
- **Uploads.** The bootstrap loads `tests/Support/namespace-overrides.php`, where `is_uploaded_file()` accepts exactly
  the temp files a test lists in `Support\Overrides::$uploadedFiles` (reset after every test). Uploads therefore go
  through the real submission and verifier handlers.
- **Constants and `WP_DEBUG`.** A test that needs `FABRICATOR_SEAL_MASTER_KEY` defined, or `WP_DEBUG` off
  (`FABRICATOR_TESTS_WP_DEBUG=0`, read by `wp-tests-config.php`), runs with `#[RunInSeparateProcess]`, so no later test
  sees the constant. The bootstrap sets `WP_TESTS_SKIP_INSTALL=1` after installing, so the child process reuses the
  tables instead of dropping them.
- **Outgoing HTTP from mPDF.** mPDF fetches URLs with its own curl, which `pre_http_request` doesn't see.
  `Support\RequestRecorder` is a local HTTP server that logs what reaches it. It answers immediately; a silent listener
  would leave mPDF waiting forever.
- **Uninstall.** `uninstall.php` declares a global function, so `UninstallTest` must stay the only test that includes
  it.
- `Fields\FieldBehaviourTest` is the former WP_DEBUG field test page, ported check for check.

### JS suite

Tests in `tests/js/`, run with Node's built-in `node:test` and jsdom. Dev-only; nothing ships.

**How it works.** `tests/js/build-fixture.php` first writes out what the PHP side hands front.js:
`Assets::frontLocalization()`, `Assets::frontFieldAssets()` and real `FormRenderer` markup. Each test then loads the
real `assets/js/front.js` into a fresh jsdom page.

`Assets::frontFieldAssets()` is the **one** place the per-field JS is assembled. Production and the test fixture both
call it. Don't rebuild the globals anywhere else.

#### Front-end and server must agree on conditions

Conditional logic runs twice: in front.js while the user fills in the form, and in FormProcessor when the form is
submitted. Both sides must reach the same result.

- **Parity test.** `condition-parity.test.js` checks front.js's condition logic against
  `FormProcessor::evalConditionRule()` on every case the fixture lists, and whole cascades against
  `FormProcessor::resolveVisibility()`. A change to either side must keep them agreeing. Add new edge values to the
  fixture.
- **Hidden fields read as empty.** `BaseField::hiddenConditionValue()` returns `[]` for fields whose inputs are
  checkboxes under their own id, and `''` otherwise. A new field type that renders checkboxes named after its id
  overrides `hiddenConditionValue()`.
- **Hidden fields are never validated or required**, Consent, GDPR and CAPTCHA included.
- **Passes.** Evaluation starts from "all visible" and repeats until nothing changes, capped by
  `FormProcessor::MAX_CONDITION_PASSES` and front.js's `MAX_PASSES`. **The two caps must stay equal.**
- **What a rule reads from a field** is `BaseField::conditionValue()` on the server and `getFieldValue()` in front.js.
  They must agree on the real markup:

  | Inputs | Read as |
  |---|---|
  | named `id` or `id[]` | the posted value |
  | named `id[key]` (expanded Name/Address, Direct Debit, range slider, Post data) | the non-empty values in render order, joined by a space (`joinedSubValues()`; `_` keys skipped) |
  | file input | the number of files |
  | CAPTCHA | its answer: ALTCHA's payload (posted under the id) or reCAPTCHA's token |

  A new field type whose inputs aren't named after its id overrides `conditionValue()` and gets a case in
  build-fixture.php's `$compositeCases`.
- **Text is compared as the server receives it.** front.js's `sanitizeLikeWp()` mirrors `sanitize_text_field()` (and
  the textarea variant). It is checked against the real functions, which `tests/js/wp-sanitize.php` runs in a process
  of its own for the fixture.

#### Admin scripts

The admin scripts load in `support/admin-page.js` with what WordPress hands them, from the same fixture:
`FormEditor::render()`'s markup and `builderI18n()` for the builder, and `FormList::render()` and
`FormSettings::renderSettingsPage()` with the objects they localize.

- Drive the scripts through their own controls and check what they send (a stubbed `fetch()`), not through test hooks.
- Work an admin script defers to animation frames (jsdom paces them at display rate) is awaited with `until()`, not a
  fixed number of `settle()` rounds, which sometimes end too early.

#### jsdom quirks

- jsdom lacks `CSS.escape()`. `support/page.js` supplies the spec algorithm only when it is missing.
- Browser APIs jsdom doesn't have (canvas drawing, layout) are out of reach here and belong to the E2E tier.
- front.js boots on `DOMContentLoaded`, which jsdom fires *after* `loadPage()` returns. A test that needs the booted
  page (whole forms from `fixture.forms`) awaits it first; see `open()` in `form-flow.test.js`.

### Manual checks

`TESTING.md` holds only what needs a person.

- If TESTING.md lists a bug fix as a manual regression check and the bug can be reproduced without a browser, write a
  test that reproduces it instead.
- When a test takes over an item, remove the item from TESTING.md and add the test to its "What is automated" table.
  Keep any manual remainder (the part no test reaches) rather than dropping the whole item.
- Section numbers in TESTING.md stay fixed: tests cite them.

---

## Security review

When reviewing or significantly modifying a file, check it against these frameworks:

| Framework | Focus |
|---|---|
| **Best Practices** | Clean code, modularity, error handling, performance |
| **OWASP Top 10** | Injection, broken access control, SSRF, XSS, CSRF, insecure deserialization, etc. |
| **CERT Coding Standards** | Insecure constructs, undefined behaviour, input validation |
| **NIST SSDF** | Protecting software, producing secure software, responding to vulnerabilities |
| **OWASP ASVS** (Level 1–2) | Testable checklist version of Top 10: auth/session, cryptography, business logic (rate limiting, replay), file/resource handling, config hygiene. Level 3 (nation-state threat model) is out of scope for this plugin. |
| **WordPress Security Sniffs** | Nonce verification, output escaping, input sanitization, `$wpdb` preparation, capability checks: the `WordPress.Security.*`/`WordPress.DB.PreparedSQL*` sniff categories specifically, not general WPCS style rules (those belong to the day-to-day `.phpcs.xml` PSR-1/2 gate). |
| **GDPR / Data-Protection-by-Design** | Data minimization, storage limitation, right-to-erasure, consent validity (freely-given vs. forced, timestamped/demonstrable per Art. 7(1)), third-party data flows (e.g. reCAPTCHA, IBAN lookups) and their disclosure. A compliance review, not a code-pattern check. |
| **CWE Top 25** | Language-agnostic cross-check against OWASP/CERT: path traversal, command/code injection, integer overflow, uncontrolled resource consumption, unrestricted upload, null deref, unsafe deserialization, hardcoded credentials, via `pheromone/phpcs-security-audit`'s `BadFunctions`/`Misc` sniffs (its Drupal-specific sniffs are excluded). |

OWASP ASVS and GDPR are checklist/compliance frameworks with no linter. Evaluate them by hand.

### Linters

**`.phpcs.xml`: style.** The day-to-day PSR-1/PSR-2 gate. Run `vendor/bin/phpcs`. **New code adds no warnings.**

**`.phpcs-security.xml`: security.** Covers the WordPress-Security and CWE-Top-25 rows of the table above, using
`wp-coding-standards/wpcs`'s security sniffs (including `WordPress.PHP.IniSet`) and `pheromone/phpcs-security-audit`,
both dev dependencies. Run `vendor/bin/phpcs --standard=.phpcs-security.xml`.

- **Errors are a release gate.** The build runs it with `-n` (errors only), because WordPress.org's Plugin Check
  rejects the same errors.
- **Warnings are review material, not a gate.** The security-audit sniffs flag *any* filesystem or callback call with a
  non-literal argument. That is a heuristic, not proof of a real issue. For a confirmed false positive, add a targeted
  `phpcs:ignore` comment with a justification rather than restructuring sound code.
- **A `phpcs:ignore` covers only the next line.** Plugin Check doesn't know this ruleset's `customSanitizingFunctions`,
  so every line using `Cast::stringOrDefault()` still needs its own ignore.

---

## Translations

**What is in the repo, and what ships**

| File | In repo | Ships |
|---|---|---|
| `languages/formfabricator.pot` (source strings) | Yes | Yes |
| `languages/formfabricator-de_DE.po` / `.mo` (German) | Yes, for submission via translate.wordpress.org | No |
| `languages/make-pot.php`, `languages/compile-mo.php` (tools) | Yes | No |

The `.po`/`.mo` don't ship because WordPress loads them itself: since WP 4.6, core auto-loads a plugin's translation
from `wp-content/languages/plugins/` the first time its text domain is used, once the plugin is approved and a
translation is published there. Nothing in the plugin's own code loads a bundled `.mo` at runtime.

The release build strips the `.po`/`.mo` and both tools from the package, and its verification step fails the build if a
tool slips in.

**Updating the `.pot`.** The `.pot` is generated, never hand-edited. `languages/make-pot.php` stands in for
`wp i18n make-pot`. Run it after adding or changing any translatable string:

```
php languages/make-pot.php            # rewrite languages/formfabricator.pot
php languages/make-pot.php --check    # exit 1 if it is out of date (pre-release gate)
```

It is idempotent: when nothing changed, a re-run rewrites nothing, not even the timestamp.

**Updating the `.mo`.** After editing a `.po` by hand, recompile it with the bundled compiler (no `msgfmt` or WP-CLI
needed):

```
php languages/compile-mo.php languages/formfabricator-de_DE.po
```
