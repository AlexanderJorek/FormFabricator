# CLAUDE.md — formfabricator

## Project Goal

Build a custom WordPress form plugin (`FormFabricator`). The admin UI is similar to Forminator's drag-and-drop builder;
the implementation approach is open as long as it is performant and secure.

The project's rules (layout, fields, data storage, PDF system, coding rules, linters, tests, translations) are in
CONTRIBUTING.md, which applies in full:

@CONTRIBUTING.md

The design decisions listed there under "Front-end assets" (form selections rendering every listed form, inlined field
assets) are known, accepted trade-offs. Don't re-report them.

Code comments must stand on their own: never cite CLAUDE.md (it is not published) or a version number or past bug in a
comment. Point to CONTRIBUTING.md only from dev-only files; shipped code states the rule in the comment itself.

## Security & Code-Quality Review Protocol

Whenever reviewing or significantly modifying a file, silently evaluate it against the frameworks in CONTRIBUTING.md's
"Security review" table and report any findings (severity: Critical / High / Medium / Low). Provide file + line,
violated standard, risk description, and a fixed code snippet for each finding. Flag GDPR findings even when no line of
code is technically "wrong."

Output format for each finding:

```
[SEVERITY] File:line — Standard violated
Risk: <one sentence>
Fix:
<minimal corrected code snippet>
```

After all findings: one paragraph of strategic recommendations for long-term NIST SSDF / CERT alignment.
