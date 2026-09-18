# Security Policy

## Supported versions

Only the latest stable release line receives security fixes. Older tags (including prior certified ZIPs) are immutable and are not re-cut; fixes ship in the next release lineage on `develop`.

## Responsible disclosure

If you believe you have found a security vulnerability in SScribe:

1. **Do not** open a public GitHub issue, discussion, or pull request about it.
2. Report privately via a [GitHub Security Advisory](https://github.com/SimplixInnovations/sscribe-export-site-pages/security/advisories/new) for this repository (private by default, visible only to maintainers until triaged).
3. Include:
   - Affected version(s) / ZIP SHA-256 if applicable
   - Environment (WordPress + PHP versions, single-site vs multisite)
   - Step-by-step reproduction (request sequence, roles/capabilities involved)
   - Impact assessment (what an attacker gains, and under which capabilities)
   - Suggested fix or mitigation, if known
4. Allow maintainers time to triage, fix on `develop`, and cut a release before any public disclosure. Coordinated disclosure only.

## Expected response workflow

1. Acknowledgement of receipt.
2. Triage and severity assessment; reproduction against a clean install.
3. Fix developed on `develop` with regression tests proving containment/authorization.
4. Release cut with updated `docs/SECURITY_MATRIX_*` evidence where applicable.
5. Coordinated public disclosure crediting the reporter (unless anonymity is requested).

## Dependency / security-update policy

- `composer audit --locked` and `npm audit` run in setup and in the `verify` gate; high/critical findings block releases.
- Dependency upgrades are isolated, reviewed commits with full gate evidence — never bundled silently with feature work.

## Scope notes

- Test fixtures, E2E scaffolding, and local testbench credentials are out of scope for production impact assessment, but hygiene reports are still welcome.
- Do not publish secrets, private infrastructure details, or customer data in any report or proof of concept.
