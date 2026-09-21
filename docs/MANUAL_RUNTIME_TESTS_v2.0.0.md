# Manual / Runtime Tests — governance schema v2.0.0

## Why this exists

Phase 69 of the v2.0.0 release-hardening spec says verbatim:

> Automated tests are necessary but not sufficient. Before declaring
> ready, run the exact ZIP on a realistic site. At least test:
> standard WordPress, WordPress + WPML, Redis object cache ON,
> Redis object cache OFF. Where possible: OpenLiteSpeed,
> Cloudflare/proxy environment (relevant to original production
> failures).

This document is the **canonical manual-test runbook** for the current
release declared by `SSCRIBE_VERSION`. The filename is retained as the
Phase 69 governance-schema identifier. Every environment below MUST be
exercised on the EXACT versioned release ZIP
(`dist/sscribe-export-site-pages-{VERSION}.zip`) — not on the repository
working tree — before the release can be declared ready.

The companion verifier `scripts/verify-manual-runtime-tests.php`
asserts the runbook exists with the canonical sections and the
six required environment rows. The companion PHPUnit integration
test pins the verifier at the PHPUnit boundary.

## Canonical scenarios

Each row below records a required environment + the canonical
exercises the reviewer must perform. Status defaults to **TODO**
until a release-engineer runs through the runbook on the final
ZIP and records evidence in `docs/CI_EVIDENCE_v2.0.0.md`.

| #  | Environment                      | Required exercises                                                                                                                                                                                | Status     |
|----|----------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------|
| 1  | Standard WordPress               | Activate the ZIP on a fresh WP install; configure export; Preview; export one format; download; Debug state; deactivate/reactivate; uninstall.                                                     | TODO       |
| 2  | WordPress + WPML                 | Activate WPML with ≥ 2 active languages; verify Language cards render; export All Languages; verify batch response includes `__all__`; verify single-language export.                            | TODO       |
| 3  | Redis object cache ON            | Enable Redis object cache (e.g. Redis Object Cache plugin); repeat the export flow; verify the rate-limit counter advances monotonically across 50 successive calls; verify no `wp_cache_*` failures. | TODO       |
| 4  | Redis object cache OFF           | Disable Redis; repeat the export flow; verify the transients-fallback path serves the rate-limit counter correctly; verify no warnings.                                                            | TODO       |
| 5  | OpenLiteSpeed (where possible)   | Deploy to an OpenLiteSpeed fronting Apache-style WordPress; verify the export download (single-shot, `dl_token`) survives the LSAPI boundary; verify no `X-LiteSpeed` header rejection.            | TODO       |
| 6  | Cloudflare / proxy environment   | Front the WP install with Cloudflare (or equivalent); verify the export download is delivered without `403` from the WAF; verify the rate-limit `Retry-After` survives the proxy without re-encoding. | TODO       |

## Per-scenario acceptance criteria

For every row above, the reviewer MUST verify the following
acceptance criteria hold end-to-end:

1. **Activation succeeds** without PHP warning or fatal.
2. **Export Preview** returns a non-zero count for at least one
   `(post_type, language, status)` tuple.
3. **Export batch** completes without terminal 5xx; the batch
   response includes `languages.__all__` when All Languages is
   selected.
4. **Export finalize** emits a download URL with a fresh
   `dl_token`; the token is single-use (rotation observed on a
   second download attempt).
5. **Export download** succeeds the first time and rejects the
   second time with a 401/403 (token-rotation invariant).
6. **Debug state** is OFF by default; toggling ON refreshes the
   console; an operational error recorded during export is
   retrievable from the Debug console with a request reference.
7. **Deactivate / reactivate** preserves user settings and
   scheduled-event cleanup; **uninstall** removes options,
   transients, scheduled events, private files, and logs.

## What "exact ZIP" means

The reviewer MUST install the artifact produced by
`composer release`, not the working tree, and not a
`vendor-prefixed/` directory. The allowed commands are:

```bash
# 1. Build the certified ZIP.
composer release

# 2. Resolve VERSION from SSCRIBE_VERSION and confirm the SHA-256 of
#    dist/sscribe-export-site-pages-{VERSION}.zip matches the certified evidence.
VERSION=$(grep -E '^ \\* Version:' sscribe-export-site-pages.php | awk '{print $3}')
sha256sum "dist/sscribe-export-site-pages-${VERSION}.zip"

# 3. Upload via WordPress → Plugins → Add New → Upload Plugin.
#    Do NOT unzip and copy/paste.
```

A reviewer who installed the working tree instead of the certified
ZIP invalidates the runbook result.

## Evidence recording

For each scenario above, the reviewer records:

- Date / time of the run.
- WordPress version (e.g. 6.6.2) + PHP version (e.g. 8.3.11).
- The exact SHA-256 of `dist/sscribe-export-site-pages-{VERSION}.zip`.
- A one-line PASS/FAIL summary per acceptance criterion above.
- A short note on any deviation.

Evidence is captured in `docs/CI_EVIDENCE_v2.0.0.md` under a
`## Manual runtime evidence — <date>` heading.

## How an independent auditor verifies this

```bash
# 1. Run the runbook gate.
composer test:manual-runtime-tests

# 2. Confirm the runbook covers all 6 environments.
grep -E '^\| [0-9]+ +\|' docs/MANUAL_RUNTIME_TESTS_v2.0.0.md

# 3. Confirm the evidence is recorded.
grep -E '^## Manual runtime evidence' docs/CI_EVIDENCE_v2.0.0.md

# 4. Run the integration test.
vendor/bin/phpunit tests/Integration/SScribe_Manual_Runtime_Tests_Test.php
```

A green `composer test:manual-runtime-tests` + a recorded
`## Manual runtime evidence` heading = the runbook is intact.

## What this contract does NOT cover

- **Automated regression** — that is Phase 41 (coverage),
  Phase 49 (AJAX security), Phase 58 (acceptance matrix), etc.
  The Phase 69 contract is strictly about MANUAL runtime
  verification on real environments.
- **Production-load stress testing** — beyond the 50-call counter
  advancement assertion in scenario 3, Phase 69 does not mandate
  load testing. A future milestone may add this.
- **WPML licensing** — real-WPML testing may require a licensed environment. The Phase 69
  runbook documents the EXERCISES; whether those exercises are
  executed against real WPML or a fixture is a release-engineering
  decision recorded in evidence.

## Change log

- 2026-09-03: Initial Phase 69 runbook + verifier + PHPUnit pin.
  All 6 environments listed with TODO status. Acceptance
  criteria documented. Evidence-recording section added.
- 2026-09-20: Reconciled the runbook with the current-version governance model and the canonical versioned release ZIP path.
