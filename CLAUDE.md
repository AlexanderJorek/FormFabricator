# CLAUDE.md — formfabricator

## Project Goal

Build a custom WordPress form plugin (`FormFabricator`).

## Directory Layout

```
assets/               — CSS, JS front-end assets
includes/             — PHP plugin source (Admin, Fields, Form, PDF, Utils)
includes/PDF/templates/ — mPDF layout templates
vendor/               — Composer dependencies + manually-vendored pdf.js
formfabricator.php    — plugin entry point
uninstall.php         — cleanup on uninstall
```

## Scope

### Fields (implemented)

Slugs below are what `getType()` returns — the authoritative name, which `FieldRegistry::FIELD_MAP`
only documents:

- text, textarea, email, name, phone, number
- address, date, time, currency
- select, radio, checkbox (multiple choice)
- upload, signature (canvas)
- rating, slider
- captcha, consent, gdpr
- html, group (section), pagebreak, page-header
- postdata, website
- **sepa** — composite field (IBAN with masked input, BIC, account holder, static creditor info block, signature canvas)

### Data Storage

No form submission data is ever stored locally. All data goes to email and/or PDF only. No custom DB tables for entries.

### PDF System

PDF generation lives in `includes/PDF/`. The mPDF-based generator (`Generator.php`) uses
`includes/PDF/templates/layout.php`; `HashSeal.php` signs each document and `PdfUtils.php` +
`GuardedPdfParser.php` read one back for the verification page. It reaches the mail side through the
`fabricator_forms_submission` action, which `includes/Form/MailSender.php` handles.

### UI / Admin

Similar to Forminator's drag-and-drop builder. Implementation approach is open as long as it is performant and secure. A lightweight JS solution is preferred over a heavy framework.

## Security & Code-Quality Review Protocol

Whenever reviewing or significantly modifying a file, silently evaluate it against the frameworks below and report any findings (severity: Critical / High / Medium / Low). Provide file + line, violated standard, risk description, and a fixed code snippet for each finding.

| Framework | Focus |
|---|---|
| **Best Practices** | Clean code, modularity, error handling, performance |
| **OWASP Top 10** | Injection, broken access control, SSRF, XSS, CSRF, insecure deserialization, etc. |
| **CERT Coding Standards** | Insecure constructs, undefined behaviour, input validation |
| **NIST SSDF** | Protecting software, producing secure software, responding to vulnerabilities |
| **OWASP ASVS** (Level 1–2) | Testable checklist version of Top 10 — auth/session, cryptography, business logic (rate limiting, replay), file/resource handling, config hygiene. Level 3 (nation-state threat model) is out of scope for this plugin. |
| **WordPress Security Sniffs** | Nonce verification, output escaping, input sanitization, `$wpdb` preparation, capability checks — the `WordPress.Security.*`/`WordPress.DB.PreparedSQL*` sniff categories specifically, not general WPCS style rules (those belong to the day-to-day `.phpcs.xml` PSR-1/2 gate, not this review pass). |
| **GDPR / Data-Protection-by-Design** | Data minimization, storage limitation, right-to-erasure, consent validity (freely-given vs. forced, timestamped/demonstrable per Art. 7(1)), third-party data flows (e.g. reCAPTCHA, IBAN lookups) and their disclosure. Compliance review, not a code-pattern check — flag findings even when no line of code is technically "wrong." |
| **CWE Top 25** | Language-agnostic cross-check against OWASP/CERT: path traversal, command/code injection, integer overflow, uncontrolled resource consumption, unrestricted upload, null deref, unsafe deserialization, hardcoded credentials — via `pheromone/phpcs-security-audit`'s `BadFunctions`/`Misc` sniffs (Drupal-specific sniffs in that package are irrelevant here and excluded). |

Output format for each finding:

```
[SEVERITY] File:line — Standard violated
Risk: <one sentence>
Fix:
<minimal corrected code snippet>
```

After all findings: one paragraph of strategic recommendations for long-term NIST SSDF / CERT alignment.

### Linters

- `.phpcs.xml` — day-to-day PSR-1/PSR-2 style gate. Run: `vendor/bin/phpcs`.
- `.phpcs-security.xml` — dedicated ruleset for the WordPress-Security and CWE-Top-25 parts of the table above (`wp-coding-standards/wpcs`'s security sniffs incl. `WordPress.PHP.IniSet` + `pheromone/phpcs-security-audit`, both installed as dev dependencies). Run: `vendor/bin/phpcs --standard=.phpcs-security.xml`. Its **errors** are a release gate in `build.ps1` (`-n`, errors only): WordPress.org's Plugin Check rejects the same ones. Expect warning noise — the security-audit sniffs flag *any* filesystem/callback call with a non-literal argument as a heuristic, not proof of a real issue — so treat its warnings as review material to triage, not a pass/fail gate; use targeted `phpcs:ignore` comments with a justification (matching the existing convention in this codebase) for confirmed false positives rather than restructuring sound code to satisfy the sniff. A `phpcs:ignore` applies only to the next line, and Plugin Check does not know this ruleset's `customSanitizingFunctions`, so lines using `Cast::stringOrDefault()` still need their own ignore.
- No installable linter exists for OWASP ASVS or GDPR/data-protection-by-design — both are checklist/compliance frameworks, not code-pattern standards. Evaluate those manually per the table above.

### Tests

`composer test` runs the PHPUnit `unit` and `perf` suites (`phpunit.xml.dist`, `tests/`); `build.ps1` runs the same as
a release gate and `.github/workflows/tests.yml` runs it on PHP 8.1–8.4 with the phpcs gates. Neither suite loads
WordPress: WordPress functions are stubbed per test with Brain Monkey, and `tests/Support/FakeWordPress.php` is an
in-memory options/transients/cron/`$wpdb` for stateful helpers (HashSeal, OptionMutex, VerifierCleanup).

- Test the real code. Where the logic is a private step of a large class, call it through `Support\Reflect` rather
  than copying the regex into the test — a copied oracle keeps passing after the production code changes.
- Tests that pin a rewrite keep the old implementation as a reference oracle and compare on seeded random input
  (`StreamScanTest`, `ObjectIndexTest`, `VerifierScansTest`). Seed with `mt_srand()` so a failing case reproduces.
- Anything that needs real WordPress (wp_kses, wp_mail, capabilities, uninstall, multisite) belongs in the
  `integration` suite, not in more stubs.
- The PHP 8.1 CI job skips `perf` (`memory_reset_peak_usage()` is 8.2+).

`composer test:integration` runs the integration suite (`phpunit-integration.xml.dist`, `tests/Integration/`): real
WordPress from `vendor/roots/wordpress-no-content`, the core test library `wp-phpunit/wp-phpunit`, and a MySQL/MariaDB
database whose tables it recreates (`WP_TESTS_DB_HOST`/`_NAME`/`_USER`/`_PASSWORD`, defaults `127.0.0.1`,
`wordpress_test`, `root`, `root`). `WP_MULTISITE=1` runs it as a network. `build.ps1` runs it as a release gate,
single site and multisite, against a throwaway portable MariaDB that `build-testdb.ps1` downloads once (pinned
SHA-256), keeps in `%LOCALAPPDATA%\FormFabricator	est-db` (about 100 MB) and starts only for that step; CI runs it
against a MySQL 8 service. An offline build (`-SkipAudit`) without that database yet skips the suite with a warning.

- WordPress's test library assumes PHPUnit 9. Extend `Integration\TestCase` or `Integration\AjaxTestCase`, never
  `WP_UnitTestCase` directly: `Support\PhpUnit10Compat` replaces the one method that breaks on PHPUnit 10. Polyfills
  stay on 2.x, the last line that supports PHPUnit 10.
- Submissions go through the real AJAX action: `AjaxTestCase::submit()` posts with a nonce and a fresh one-time token
  as front.js does; mail lands in WordPress's MockPHPMailer (`sentMail()`).
- mPDF fetches URLs with its own curl, invisible to `pre_http_request`; `Support\RequestRecorder` is a local HTTP
  server that logs what reaches it. It answers at once — a silent listener leaves mPDF waiting forever.
- `uninstall.php` declares a global function, so `UninstallTest` must stay the only test that includes it.
- `Fields\FieldBehaviourTest` is the former WP_DEBUG field test page, ported check for check.
- Locally: PHP needs `mysqli` in the child process that installs WordPress too, so enable it through `php.ini` or
  `PHP_INI_SCAN_DIR`, not `-d` — the build does the latter when `php.ini` does not load it.

`npm test` runs the JS suite (`tests/js/`, Node's built-in `node:test` + jsdom; dev-only, nothing ships):
`tests/js/build-fixture.php` first writes what the PHP side hands front.js — `Assets::frontLocalization()`,
`Assets::frontFieldAssets()` and real `FormRenderer` markup — then each test loads the real `assets/js/front.js` into
a fresh jsdom page. Also a release gate in `build.ps1` and a CI job.

- `Assets::frontFieldAssets()` is the one assembly of the per-field JS; production and the
  JS test fixture both call it. Don't rebuild the globals elsewhere.
- `condition-parity.test.js` checks front.js's condition logic against `FormProcessor::evalConditionRule()` on every
  case the fixture lists. A change to either side must keep them agreeing; add new edge values to the fixture.
- jsdom lacks `CSS.escape()`; `support/page.js` supplies the spec algorithm only when it is missing. Browser APIs
  jsdom does not have (canvas drawing, layout) are out of reach here and belong to the E2E tier.
- A bug fix that TESTING.md lists as a manual regression check gets a test that reproduces it instead, where the
  bug can be reproduced without a browser.
- TESTING.md holds only what needs a person. When a test takes over an item, remove it there and add the test to
  its "What is automated" table; keep an item's manual remainder (the part no test reaches) rather than dropping
  the whole item. Section numbers stay fixed — tests cite them.

### JS Assets

Static files in `assets/js/` served directly — no build step unless explicitly added.

`Assets::enqueueFront()` inlines every field type's CSS and JS into any page that renders a form,
whether or not the form uses those fields — 88 KB measured (24 KB across 22 CSS files, 64 KB across
29 JS files), inline and so re-sent per page view rather than cached. This is a **known, accepted
trade-off**, decided deliberately: the per-field JS files are function-expression fragments
assembled into `window.FabricatorFieldInits`/`FabricatorValidators`/`FabricatorEmptyChecks`, so they
cannot be enqueued as standalone scripts without changing how field scripts reach the browser, and
splitting only the CSS risks styles arriving after `wp_head` in widget, block-template and
page-builder placements. Don't re-report it; reopen it only with a plan that covers those
placements.

### Scanning untrusted PDF bytes

A scan over uploaded PDF bytes must be **provably single-pass**. Three separate findings came from
breaking this: `strpos()`/`preg_match()` with a start offset scans to end-of-file when there is no
match, so doing that once per object or per stream is quadratic, and `preg_match_all()` with
`PREG_OFFSET_CAPTURE` over a whole file costs several times the file's own size in memory — more
than `MemoryBudget` reserved, which turns a refusal into a PHP memory fatal.

So, in `PdfUtils` and the verifier:

- Walk with a cursor that only moves forward (`PdfUtils::nextStreamBody()` is the pattern), or with
  `preg_match(..., $pos)` in a loop where `$pos` strictly advances.
- Never build an index of every match in the file; yield results instead (`streamBodies()` is a
  `Generator` for exactly this reason).
- When a needle is absent once (no `endstream` ahead, no `endobj` ahead), it is absent for every
  later offset too — latch it and stop rather than searching again.
- Before claiming a scan is fixed, measure it: feed it the pathological shapes (many objects with no
  streams, many tiny streams, a file of bare `stream` keywords, no `endstream` at all) and assert
  both wall time and peak memory. `tests/Perf/PdfScanPerfTest.php` does this — add the new shape there, and
  check it fails against the unfixed code (time is asserted as a growth exponent, so it needs a shape where the
  quadratic version really is quadratic; see `tests/Support/Measure.php`).

The tools for this, in `PdfUtils`:

- `nextAt($haystack, $needle, $offset, $cache)` — strpos() for a forward walk. Always returns exactly
  strpos()'s answer; reuses it while it lies ahead and remembers "none". Use it for any per-object
  or per-marker search instead of a bare strpos().
- `objectDefinitionIndex()` + `definitionFromIndex()` — every object's last definition in one pass,
  then O(1) lookups with exactly `lastObjectDefinition()`'s answer. Resolving references one whole-file
  search at a time costs "number of references × file size", and the uploader picks the number.
- `MAX_OBJECTS` and `declaredObjectCount()` — the ceiling. The verifier refuses a file past it before
  anything indexes it; every index that grows with attacker-chosen counts (objects, references,
  names) is held to it or throws `LengthException('Too many objects…')`, which the verifier maps to
  its refusal message. A document from this plugin holds a few thousand objects at most.
- Read dictionary keys from the dictionary (the bytes before `stream`), never from the whole object:
  image data can hold any bytes, including every key name.
- Exception messages stay constant strings; the security gate treats a concatenated one as unescaped
  output. Put the details in the log line beside the throw.

### No HEREDOC/NOWDOC

WordPress.org prohibits HEREDOC/NOWDOC syntax in hosted plugins (their codesniffers can't
verify escaping inside them) — this is an absolute rule, not a style preference. Field classes'
embedded CSS/JS (`getStyles()`, `getClientInit()`, and any `fn` string inside
`getClientValidation()`/`getClientEmptyCheck()`) live in their own real `.css`/`.js` files under
`assets/css/fields/`/`assets/js/fields/`, named after the class (e.g. `UploadField.css`), and are
read via `BaseField::readFieldAsset()` — giving real syntax highlighting/linting instead of a PHP
string. When a class needs more than one such asset (multiple validation rules, an empty-check
function), suffix the filename with the rule/purpose, e.g. `SepaField.iban.js`,
`SepaField.sepa-bic.js`. Only a short, genuinely one-line JS/CSS literal may stay inline as a
plain PHP string — the external-file split is for content that would otherwise need
`. "\n" .`-joined concatenation. See `readFieldAsset()` in `BaseField.php`, and any field's
`getStyles()`/`getClientInit()`/`getClientValidation()` for the pattern.

### Translations

`languages/formfabricator.pot` (source strings) and `languages/formfabricator-de_DE.po`/`.mo` (German)
are hand-maintained in the repo, for submission via translate.wordpress.org — `build.ps1` strips
the `.po`/`.mo` from the shipped package (only the `.pot` ships), since WordPress core auto-loads
a plugin's translation from `wp-content/languages/plugins/` on first use of its textdomain since
WP 4.6, once this plugin is approved and a translation is published there. Nothing in this
plugin's own code loads a bundled `.mo` at runtime. There is no `msgfmt`/WP-CLI in this dev
environment — to regenerate the `.mo` after editing a `.po` by hand, use the existing compiler,
don't write a new one:

```
php languages/compile-mo.php languages/formfabricator-de_DE.po
```

The `.pot` is generated, not hand-edited — `languages/make-pot.php` stands in for `wp i18n
make-pot`, which is likewise absent here. Run it after adding or changing any translatable
string, and use `--check` (exit 1 when the file on disk is stale) as a pre-release gate; it is
idempotent, so a re-run when nothing changed rewrites nothing, not even the timestamp. Both
tools are dev-only: `build.ps1` strips them from the package and its verification step fails
the build if either one slips in.

```
php languages/make-pot.php            # rewrite languages/formfabricator.pot
php languages/make-pot.php --check    # exit 1 if it is out of date
```
