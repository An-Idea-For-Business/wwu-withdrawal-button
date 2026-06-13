<!-- Thanks for contributing! Keep PRs focused. -->

## What & why

<!-- What does this change and which issue / legal requirement does it address? -->

Closes #

## Type

- [ ] Bug fix
- [ ] New feature / phase
- [ ] Documentation
- [ ] Refactor / tooling

## Checklist

- [ ] Code & comments in English; user-facing strings are i18n.
- [ ] `php -l` and `node --check` are clean on every changed file.
- [ ] No `?>` in `//` comments / no `*/` in block comments (the close-tag trap).
- [ ] HPOS-safe order access (no `get_post()` / `get_post_meta()` on orders).
- [ ] The immutable log stays append-only (no UPDATE/DELETE, no `updated_at`).
- [ ] REST permission callbacks do **not** re-verify the WP nonce.
- [ ] Updated `docs/changelog/wwu-wb-CHANGELOG.md`.
- [ ] If behaviour changed: extended the relevant smoke suite and the [compliance matrix](../blob/main/docs/legal/wwu-wb-compliance-matrix.md).

## Notes for reviewers

<!-- Anything tricky, trade-offs, follow-ups. -->
