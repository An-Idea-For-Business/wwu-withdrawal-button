# Security Policy

## Supported versions

This plugin is pre-release. Until `1.0.0`, security fixes land on `main`.

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Report privately, preferably via **GitHub Security Advisories**
("Report a vulnerability" on the repository's *Security* tab), or by email to
**info@webwakeup.it** with the subject `[SECURITY] wwu-withdrawal-button`.

Please include:

- a description of the issue and its impact;
- steps to reproduce (proof-of-concept if possible);
- affected version / commit;
- any suggested remediation.

We aim to acknowledge reports within **72 hours** and to provide a remediation
timeline after triage. We will credit reporters in the changelog unless you ask
us not to.

## Scope

This plugin handles consumer personal data (the immutable withdrawal log stores
IP addresses and contract data as legally-required evidence) and emits frontend
forms. We are especially interested in reports about: authentication/ownership
bypass on withdrawal requests, token forgery, enumeration of orders, tampering
with the append-only hash-chained log, PDF/template path traversal, and any
data leak of another customer's order or receipt.
