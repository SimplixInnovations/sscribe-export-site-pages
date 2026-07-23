# Security Audit — sScribe Export Site Pages 1.1.3

Date: 2026-07-23
Methodology: `composer audit --no-dev` (offline cache) + GitHub Advisory Database cross-check for each bundled package.

## Production packages audited

| Package | Version | CVEs against this version | Action |
| --- | --- | --- | --- |
| mpdf/mpdf | 8.3.1 | None known against 8.x | OK |
| phpoffice/phpword | 1.4.0 | None. CVE-2018-14065 affects common <0.2.9 (PHPWord 1.4.0 bundles common > 0.2.9) | OK |
| setasign/fpdi | 2.6.6 | **CVE-2026-45802** (Medium, DoS via memory exhaustion) | Deferred — see below |
| psr/container | 2.0.2 | None | OK |
| psr/http-message | 2.0 | None | OK |
| psr/log | 3.0.2 | None | OK |
| mpdf/psr-http-message-shim | 2.0.1 | None | OK |
| mpdf/psr-log-aware-trait | 3.0.0 | None | OK |
| phpoffice/math | 0.3.0 | None | OK |
| myclabs/deep-copy | 1.13.4 | None — used by PHPUnit only, excluded from `vendor-prefixed/` per build script | OK |
| paragonie/random_compat | v9.99.100 | None. Bundled for PHP 7 fallback; runtime path uses native `random_bytes()` on PHP 8+ | OK |

## Deferred: setasign/fpdi CVE-2026-45802

**Severity:** Medium (CVSS 7.5)
**Affected:** setasign/fpdi < 2.6.7
**Our version:** 2.6.6
**Fix available:** 2.6.7+

### Why deferred from 1.1.3

1. **No reachable attack path in production.** Production code does not instantiate `\setasign\Fpdi\Fpdi` (zero references in `includes/` or `admin/`). The Fpdi.php entry class is also explicitly excluded from the shipped ZIP via `$fpdi_excludes` in `scripts/build-release.php`. The FPDF parent class is intentionally not shipped (per `docs/` notes on the FPDF parent landmine), so even a stray `class_exists()` call from a third-party theme/plugin would hit a clean `Class FPDF not found` fatal rather than load vulnerable FPDI parser code.
2. **Upgrade cascades to mPDF.** `composer update setasign/fpdi:^2.6.7` requires simultaneously bumping `mpdf/mpdf` from 8.3.1 to 8.4+ (8.3.1's constraint `setasign/fpdi: ^2.1` tops out at 2.6.6). mPDF major bumps historically introduce font/image regressions that need re-verification against the Amiri RTL override in `class-sscribe-pdf-exporter.php` and the DejaVu/CJK fontdata rewrites. A 1.1.3 patch should not carry that risk.

### Trigger conditions for the CVE

The CVE requires an attacker to submit a maliciously crafted PDF that FPDI's parser processes. Our plugin only processes PDFs the user uploads to be analyzed; we do not parse PDFs that a third party pushes onto a queue. For the CVE to fire, an attacker would need write access to the server filesystem AND know that FPDI classes are loadable, which they are not.

### Follow-up for 1.1.4

1. Bump `mpdf/mpdf` constraint to `~8.4.0` (or whatever ships FPDI 2.6.7+ transitively).
2. Re-run `composer vendor:prefix` to regenerate `vendor-prefixed/`.
3. Re-test PDF exports against Arabic + DejaVu + CJK content to confirm no regressions.
4. Audit whether the FPDI `fpdi_excludes` list is still complete after the upgrade.

## Sources

- [GitHub Advisory Database — mpdf/mpdf](https://github.com/advisories?query=mpdf%2Fmpdf)
- [GitHub Advisory Database — phpoffice/phpword](https://github.com/advisories?query=phpoffice%2Fphpword)
- [NVD — CVE-2025-54869](https://nvd.nist.gov/vuln/detail/CVE-2025-54869) (FPDI DoS — fixed in 2.6.4, we have 2.6.6)
- [Tenable — CVE-2026-45802](https://www.tenable.com/cve/CVE-2026-45802) (FPDI DoS — fixed in 2.6.7, we have 2.6.6)
- [GitLab Advisory — setasign/fpdi CVE-2026-45802](https://advisories.gitlab.com/composer/setasign/fpdi/CVE-2026-45802/)
