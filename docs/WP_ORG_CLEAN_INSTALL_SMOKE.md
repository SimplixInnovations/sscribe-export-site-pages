# Clean Install Activation Smoke — current release

This runbook proves the exact release package behaves correctly under the
conditions a WordPress.org reviewer will reproduce: a brand-new WordPress
installation, the exact versioned ZIP installed through the normal plugin
installer, activation, one smoke pass, deactivate/reactivate, and a zero-error
PHP log gate.

The canonical artifact pattern is:

```text
dist/sscribe-export-site-pages-{VERSION}.zip
```

`{VERSION}` means the current `SSCRIBE_VERSION` from
`sscribe-export-site-pages.php`. Release tooling and
`scripts/verify-exact-package-clean-install.php` must resolve the same value.

## Procedure

### POSIX shell

```bash
VERSION="$(php -r '$s=file_get_contents("sscribe-export-site-pages.php"); preg_match("/SSCRIBE_VERSION.[^0-9]*([0-9]+\\.[0-9]+\\.[0-9]+)/", $s, $m); echo $m[1] ?? "";')"
test -n "$VERSION"
ZIP="dist/sscribe-export-site-pages-${VERSION}.zip"
test -f "$ZIP"

composer test:wp:install

wp --path=tests-wp/_wordpress plugin install "$ZIP" --force --activate

wp --path=tests-wp/_wordpress cron event list
wp --path=tests-wp/_wordpress eval 'echo "sscribe_version=" . get_option("sscribe_version", "") . PHP_EOL;'

wp --path=tests-wp/_wordpress plugin deactivate sscribe-export-site-pages
wp --path=tests-wp/_wordpress plugin activate sscribe-export-site-pages
```

### Windows PowerShell

```powershell
$Main = Get-Content .\sscribe-export-site-pages.php -Raw
if ($Main -notmatch "SSCRIBE_VERSION[^0-9]*([0-9]+\.[0-9]+\.[0-9]+)") {
    throw "Could not resolve SSCRIBE_VERSION"
}
$Version = $Matches[1]
$Zip = ".\dist\sscribe-export-site-pages-$Version.zip"
if (-not (Test-Path $Zip)) {
    throw "Exact release ZIP missing: $Zip"
}

composer test:wp:install
if ($LASTEXITCODE -ne 0) { throw "Real-WP provisioning failed" }

wp --path=tests-wp/_wordpress plugin install $Zip --force --activate
if ($LASTEXITCODE -ne 0) { throw "Exact ZIP install/activation failed" }

wp --path=tests-wp/_wordpress cron event list
wp --path=tests-wp/_wordpress eval 'echo "sscribe_version=" . get_option("sscribe_version", "") . PHP_EOL;'

wp --path=tests-wp/_wordpress plugin deactivate sscribe-export-site-pages
wp --path=tests-wp/_wordpress plugin activate sscribe-export-site-pages
```

## Required observations

The resolved current version must be reported by the plugin. The exact numeric value
changes per release and therefore is not duplicated as a second source of truth in
this runbook.

```text
=== TABLES ===
wp_sscribe_audit_log
wp_sscribe_export_logs
wp_sscribe_export_stats

=== CRON ===
sscribe_cleanup_exports
sscribe_cleanup_sessions
sscribe_cleanup_audit_trail

=== CAPABILITIES ===
administrator: sscribe_export, sscribe_health
subscriber: neither capability

=== REACTIVATION ===
all three cron hooks remain singly scheduled

=== PHP ERROR LOG ===
zero plugin PHP errors / warnings / notices
```

## Invariants enforced

| Invariant | Expected |
| --- | --- |
| `wp_sscribe_export_logs` table created | yes |
| `wp_sscribe_export_stats` table created | yes |
| `wp_sscribe_audit_log` table created | yes |
| `administrator` has `sscribe_export` | yes |
| `administrator` has `sscribe_health` | yes |
| `subscriber` does NOT have either capability | absent |
| 3 cron events scheduled on activation | 3 |
| Same 3 cron events scheduled after reactivation | 3 |
| Exact package version equals `SSCRIBE_VERSION` | yes |
| Plugin PHP errors/warnings/notices during smoke | 0 |

## Why this matters for WordPress.org submission

A reviewer can reject or stop on activation/runtime defects such as a fatal error,
missing tables/capabilities, or broken lifecycle cleanup. The automated exact-package
contract verifies the ZIP structure and activation invariants; the Real-WordPress
and browser suites execute the corresponding runtime paths.

This file is a reproducible runbook, not release evidence by itself. Per-release
evidence belongs in the ignored/generated certification manifests so tracked
documentation never creates a source-SHA/version circularity.

## Historical baseline — v2.0.1

The v2.0.1 clean-install baseline used
`dist/sscribe-export-site-pages-2.0.1.zip` and observed the same invariant shape:
three tables, three cron hooks, two administrator capabilities, clean
deactivate/reactivate behavior, and no plugin PHP errors. Historical evidence is
retained only as context; it is not substituted for current-release execution.
