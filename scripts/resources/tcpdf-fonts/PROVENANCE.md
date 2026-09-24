# TCPDF 7 runtime font subset

This directory is a tracked, deterministic build input for the current locked
TCPDF 7.0.11 runtime and `tecnickcom/tc-lib-pdf-font` 4.4.0 package.

The DejaVu Sans runtime data was originally produced with
`tecnickcom/tc-lib-pdf-font` 4.3.3 using its upstream `util/bulk_convert.php`
converter. Those generated font bytes remain intentionally unchanged across
the current dependency maintenance update; their original generator version is
recorded here instead of being rewritten to match a later runtime package.
The current runtime dependency graph is recorded in this repository's
`composer.lock`, and release/runtime tests stage and exercise this exact tracked
font subset against that locked graph.

The Core14 descriptors originate from Tecnick's Core14 AFM source set. The
Adobe redistribution terms required for those metrics are retained at
`core/LICENSE`. DejaVu's upstream license is retained at `dejavu/LICENSE`.

SScribe intentionally stages only the standard PDF Core14 descriptors and
DejaVu Sans regular, bold, italic, and bold-italic data required by the PDF
renderer. `scripts/prune-tcpdf-for-strauss.php` copies the exact tracked
subset into `vendor/tecnickcom/tc-lib-pdf-font/target/fonts/`, verifies each
copy by SHA-256, and fails closed on a missing, empty, symlinked, or mismatched
asset. Strauss then prefixes that tree for the release.

Do not edit generated `.json`, `.z`, or `.ctg.z` files manually. Regenerate
them from the locked upstream toolchain, review the resulting diff, and retain
the corresponding upstream license notices.
