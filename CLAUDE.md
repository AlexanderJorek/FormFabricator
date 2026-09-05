# CLAUDE.md — formfabricator

## Project Goal

Build a custom WordPress form plugin (`FormFabricator`).

## Directory Layout

```
assets/               — CSS, JS front-end assets
includes/             — PHP plugin source (Admin, Fields, Form, PDF, Utils)
includes/PDF/templates/ — mPDF layout templates
vendor/               — Composer dependencies + manually-vendored pdf.js
forge-forms.php       — plugin entry point
uninstall.php         — cleanup on uninstall
```

## Scope

### Fields (implemented)

- text, textarea, email, name, phone, number
- address, date, time, currency
- select, radio, multivalue (checkboxes)
- upload, signature (canvas)
- rating, slider
- captcha, consent, gdprcheckbox
- html, feldgruppe (section), page-break
- postdata, website
- **SEPA Lastschriftmandat** — composite field (IBAN with masked input, BIC, Kontoinhaber, static creditor info block, signature canvas)

### Data Storage

No form submission data is ever stored locally. All data goes to email and/or PDF only. No custom DB tables for entries.

### PDF System

PDF generation lives in `includes/PDF/`. The mPDF-based generator uses `includes/PDF/templates/layout.php` and integrates with the plugin's mail hooks via `SubmissionHook.php` and `MailAttachments.php`.

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
- `.phpcs-security.xml` — dedicated ruleset for the WordPress-Security and CWE-Top-25 parts of the table above (`wp-coding-standards/wpcs`'s security sniffs + `pheromone/phpcs-security-audit`, both installed as dev dependencies). Run: `vendor/bin/phpcs --standard=.phpcs-security.xml`. Expect warning noise — the security-audit sniffs flag *any* filesystem/callback call with a non-literal argument as a heuristic, not proof of a real issue — so treat its output as review material to triage, not a pass/fail gate; use targeted `phpcs:ignore` comments with a justification (matching the existing convention in this codebase) for confirmed false positives rather than restructuring sound code to satisfy the sniff.
- No installable linter exists for OWASP ASVS or GDPR/data-protection-by-design — both are checklist/compliance frameworks, not code-pattern standards. Evaluate those manually per the table above.

### JS Assets

Static files in `assets/js/` served directly — no build step unless explicitly added.

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
