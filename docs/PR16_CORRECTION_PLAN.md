# PR 16 correction plan

Candidate baseline: `9de3445121996b1e05a8f8a1f484e43c8159127f`. Release remains HOLD.

## Constraints

- Reject malformed and incomplete Plugin Check evidence; strict certification requires exact source, artifact and report identity plus completed command status.
- Acknowledge warnings only by code, normalized plugin-relative source, severity and allowed status. All errors fail.
- Preserve genuine public document roots, including filesystem and drive roots; only known synthetic CLI roots may be ignored.
- Localize all debug-console UI messages and use WordPress plural rules.
- Tests must exercise actual verifier wiring, not only helpers. Local PHP/WordPress execution is unavailable here; report unexecuted checks explicitly.
- No tagging/uploading. Publish source changes to existing PR; local runtime evidence follows against the resulting SHA.

## Tasks

1. Replace permissive Plugin Check parsing and optional identity checks with structured validation, exact binding and command-level regressions. Provide a reproducible evidence capture command.
2. Fix document-root classification and containment; add genuine root and synthetic CLI regressions.
3. Complete debug-console localization/plurals and regenerate translation template; add meaningful JavaScript regression coverage.
4. Independent review, available checks, publish PR update and exact-SHA local verification handoff.
