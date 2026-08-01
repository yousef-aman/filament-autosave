# Security Policy

## Supported versions

| Version | Supported |
| --- | :---: |
| 1.x | ✅ |

## Reporting a vulnerability

Please do not report security issues in a public issue or discussion.

Report privately through GitHub's
[private vulnerability reporting](https://github.com/yousef-aman/filament-autosave/security/advisories/new),
or by email to superyousef1999@gmail.com if you cannot use GitHub.

Useful details to include: the package version, the Filament and Laravel
versions you are running, and the smallest reproduction you can manage.

Reports are acknowledged within a week. Once a report is confirmed, a patched
release is published along with a GitHub advisory, crediting you unless you ask
otherwise.

## Scope

This package writes form state to the database without running validation, by
design — see the guarantees and their limits in the
[README](README.md#edit-pages). A report that autosave skipped field-level rules
(`minLength`, `maxValue`, and similar) is documented behaviour, not a
vulnerability. Reports that a payload bypassed one of the enforced boundaries —
option/tenant scoping, `required` on a `NOT NULL` column, the password and
`except` exclusions, or the declared-field allowlist — are in scope.
