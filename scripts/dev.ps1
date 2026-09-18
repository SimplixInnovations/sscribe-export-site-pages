<#
.SYNOPSIS
    Canonical Windows developer entry point for SScribe Export Site Pages.
.DESCRIPTION
    doctor | setup | clean | test | verify | e2e | coverage | build | artifact
    Every command fails closed: any external step with a non-zero exit code
    aborts the run immediately. No exit codes are swallowed.
.EXAMPLE
    .\scripts\dev.ps1 doctor
.EXAMPLE
    .\scripts\dev.ps1 verify
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('doctor', 'setup', 'clean', 'test', 'verify', 'e2e', 'coverage', 'build', 'artifact', '')]
    [string]$Command = '',
    [string]$Zip = '',
    [string]$ExpectedHash = '',
    [long]$ExpectedBytes = -1,
    [int]$ExpectedEntries = -1,
    [switch]$Dependencies,
    [switch]$Testbench,
    [switch]$All
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot

function Invoke-DevStep {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][scriptblock]$Body
    )
    Write-Host "`n=== $Name ==="
    & $Body
    if ($LASTEXITCODE -ne 0) {
        throw "STEP FAILED: $Name (exit $LASTEXITCODE)"
    }
    Write-Host "PASS: $Name"
}

function Get-CommandVersion {
    param([Parameter(Mandatory = $true)][string]$Name)
    try {
        $cmd = Get-Command $Name -ErrorAction Stop
        $out = & $cmd.Source --version 2>&1 | Select-Object -First 1
        return @{ Found = $true; Path = $cmd.Source; Version = "$out" }
    } catch {
        return @{ Found = $false; Path = ''; Version = '' }
    }
}

function Show-DoctorRow {
    param([string]$Name, [string]$Status, [string]$Detail = '')
    Write-Host ("{0,-16} {1,-14} {2}" -f $Name, $Status, $Detail)
}

function Invoke-Doctor {
    Set-Location -LiteralPath $RepoRoot
    $failed = $false
    Write-Host 'SScribe doctor'
    Write-Host ("Repo:   {0}" -f $RepoRoot)

    try {
        $os = Get-CimInstance -ClassName Win32_OperatingSystem
        Show-DoctorRow 'Windows' 'PASS' ("{0} ({1})" -f $os.Caption, $os.Version)
    } catch {
        Show-DoctorRow 'Windows' 'UNKNOWN' 'non-Windows host?'
    }
    Show-DoctorRow 'PowerShell' 'PASS' ($PSVersionTable.PSVersion.ToString())

    foreach ($tool in @('Git', 'PHP', 'Composer', 'Node', 'npm')) {
        $info = Get-CommandVersion ($tool.ToLower())
        if ($info.Found) {
            Show-DoctorRow $tool 'PASS' ("{0} @ {1}" -f $info.Version, $info.Path)
        } else {
            Show-DoctorRow $tool 'MISSING' 'install it, then re-run doctor'
            $failed = $true
        }
    }

    $bash = Get-CommandVersion 'bash'
    if ($bash.Found) {
        Show-DoctorRow 'Git Bash' 'NOT REQUIRED' ("present @ {0}; canonical tooling no longer needs it" -f $bash.Path)
    } else {
        Show-DoctorRow 'Git Bash' 'NOT REQUIRED' 'absent; canonical tooling no longer needs it'
    }

    try {
        $mods = (php -m) -join "`n"
        $need = @('curl', 'dom', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip', 'zlib', 'phar')
        $missing = @($need | Where-Object { $mods -notmatch ("(?m)^" + $_ + "$") })
        if ($missing.Count -eq 0) {
            Show-DoctorRow 'PHP extensions' 'PASS' ($need -join ', ')
        } else {
            Show-DoctorRow 'PHP extensions' 'MISSING' ($missing -join ', ')
            $failed = $true
        }
        if ($mods -match '(?m)^xdebug$') {
            Show-DoctorRow 'Xdebug' 'PASS' 'coverage-capable'
        } else {
            Show-DoctorRow 'Xdebug' 'OPTIONAL' 'required only for coverage; install/enable php_xdebug.dll for dev.ps1 coverage'
        }
    } catch {
        Show-DoctorRow 'PHP extensions' 'UNKNOWN' 'php -m failed'
        $failed = $true
    }

    try {
        $platformOutput = & composer check-platform-reqs --no-interaction 2>&1
        $platformExit = $LASTEXITCODE
        @($platformOutput) | Select-Object -Last 3 | ForEach-Object { Write-Host ("  check-platform-reqs: {0}" -f $_) }
        if ($platformExit -eq 0) {
            Show-DoctorRow 'Composer platform' 'PASS' 'locked requirements satisfied'
        } else {
            Show-DoctorRow 'Composer platform' 'MISSING' ("composer check-platform-reqs exit {0}" -f $platformExit)
            $failed = $true
        }
    } catch {
        Show-DoctorRow 'Composer platform' 'MISSING' 'composer check-platform-reqs could not run'
        $failed = $true
    }

    if (Test-Path (Join-Path $RepoRoot 'node_modules\@playwright\test\package.json')) {
        $pwVer = (Get-Content (Join-Path $RepoRoot 'node_modules\@playwright\test\package.json') -Raw | ConvertFrom-Json).version
        $chromium = Get-ChildItem -Path (Join-Path $env:USERPROFILE 'AppData\Local\ms-playwright') -Filter 'chromium-*' -Directory -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($chromium) {
            Show-DoctorRow 'Playwright' 'PASS' ("@playwright/test {0}; chromium @ {1}" -f $pwVer, $chromium.FullName)
        } else {
            Show-DoctorRow 'Playwright' 'SETUP NEEDED' ("@playwright/test {0} installed but no Chromium; run dev.ps1 setup" -f $pwVer)
        }
    } else {
        Show-DoctorRow 'Playwright' 'SETUP NEEDED' 'run dev.ps1 setup (npm ci + playwright install)'
    }

    try {
        $devModeKey = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AppModelUnlock' -Name AllowDevelopmentWithoutDevLicense -ErrorAction Stop
        if ($devModeKey.AllowDevelopmentWithoutDevLicense -eq 1) {
            Show-DoctorRow 'Symlinks' 'PASS' 'Developer Mode on: symlink-guard tests can run (private-storage coverage)'
        } else {
            Show-DoctorRow 'Symlinks' 'LIMITED' 'Developer Mode off: symlink-guard tests skip; enable Settings > System > For developers'
        }
    } catch {
        Show-DoctorRow 'Symlinks' 'LIMITED' 'could not read Developer Mode state; symlink-guard tests may skip'
    }

    try {
        $branch = (git -C $RepoRoot rev-parse --abbrev-ref HEAD).Trim()
        Show-DoctorRow 'Branch' 'PASS' $branch
        $dirty = git -C $RepoRoot status --porcelain
        if ([string]::IsNullOrWhiteSpace("$dirty")) {
            Show-DoctorRow 'Worktree' 'CLEAN' ''
        } else {
            $n = (@($dirty) | Measure-Object).Count
            Show-DoctorRow 'Worktree' 'DIRTY' ("{0} changed path(s)" -f $n)
        }
    } catch {
        Show-DoctorRow 'Git repo' 'MISSING' 'not a git checkout?'
        $failed = $true
    }

    if ($failed) { throw 'doctor: required tooling is missing (see MISSING rows above)' }
    Write-Host "`ndoctor: all required host tooling present"
}

function Invoke-Setup {
    Set-Location -LiteralPath $RepoRoot
    Invoke-DevStep 'composer install' { composer install --no-interaction --prefer-dist }
    # The unit suite loads Strauss-prefixed vendors (SScribeVendor\*);
    # vendor-prefixed/ is gitignored by policy, so a fresh clone must
    # generate it before any test command can pass.
    Invoke-DevStep 'vendor prefixing' { composer vendor:prefix }
    Invoke-DevStep 'npm ci' { npm ci }
    Invoke-DevStep 'playwright chromium' { npx playwright install chromium }
    Invoke-DevStep 'composer validate' { composer validate --strict }
    Invoke-DevStep 'composer audit' { composer audit --locked }
    Invoke-DevStep 'npm audit' { npm audit }
}

function Invoke-Clean {
    Set-Location -LiteralPath $RepoRoot
    $targets = @(
        (Join-Path $RepoRoot 'coverage'),
        (Join-Path $RepoRoot 'clover.xml'),
        (Join-Path $RepoRoot 'playwright-report'),
        (Join-Path $RepoRoot 'test-results'),
        (Join-Path $RepoRoot 'dist\coverage-data')
    )
    $tempRoot = Join-Path $env:LOCALAPPDATA 'Temp\sscribe-export-site-pages'
    if (Test-Path -LiteralPath $tempRoot) { $targets += $tempRoot }
    if ($Dependencies -or $All) {
        $targets += (Join-Path $RepoRoot 'vendor')
        $targets += (Join-Path $RepoRoot 'node_modules')
        $targets += (Join-Path $RepoRoot 'dist')
    }
    foreach ($t in $targets) {
        if (Test-Path -LiteralPath $t) {
            Write-Host "Removing $t"
            Remove-Item -LiteralPath $t -Recurse -Force
        }
    }
    if ($Testbench -or $All) {
        Invoke-DevStep 'testbench uninstall' { composer test:wp:uninstall }
    }
    Write-Host 'clean: done (source, .git, and user files untouched)'
}

function Invoke-Test {
    Set-Location -LiteralPath $RepoRoot
    Invoke-DevStep 'composer test' { composer test }
}

function Test-WpBenchPresent {
    return ((Test-Path (Join-Path $RepoRoot 'tests-wp\_wordpress\wp-load.php')) -and (Test-Path (Join-Path $RepoRoot 'tests-wp\_wordpress-tests-lib\includes\functions.php')))
}

function Invoke-Verify {
    Set-Location -LiteralPath $RepoRoot
    Invoke-DevStep 'composer validate' { composer validate --strict }
    Invoke-DevStep 'composer audit' { composer audit --locked }
    Invoke-DevStep 'composer test' { composer test }
    Invoke-DevStep 'composer stan' { composer stan }
    Invoke-DevStep 'composer cs' { composer cs }
    Invoke-DevStep 'composer i18n:check' { composer i18n:check }
    Invoke-DevStep 'composer test:perf' { composer test:perf }
    Invoke-DevStep 'npm audit' { npm audit }
    Invoke-DevStep 'npm audit:js' { npm run audit:js }
    Invoke-DevStep 'npm lint' { npm run lint }
    Invoke-DevStep 'npm format:check' { npm run format:check }
    Invoke-DevStep 'e2e runtime-contract' { npm run test:e2e:runtime-contract }
    if (-not (Test-WpBenchPresent)) {
        Invoke-DevStep 'real WordPress testbench install' { composer test:wp:install }
    }
    Invoke-DevStep 'composer test:wp' { composer test:wp }
}

function Invoke-E2E {
    Set-Location -LiteralPath $RepoRoot
    foreach ($tool in @('node', 'npm')) {
        if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "e2e: required tool missing: $tool" }
    }
    if (-not (Test-Path (Join-Path $RepoRoot 'node_modules\@playwright\test\package.json'))) {
        throw 'e2e: @playwright/test not installed; run dev.ps1 setup first'
    }
    $chromium = Get-ChildItem -Path (Join-Path $env:USERPROFILE 'AppData\Local\ms-playwright') -Filter 'chromium-*' -Directory -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $chromium) { throw 'e2e: Chromium not installed; run npx playwright install chromium (or dev.ps1 setup)' }
    # Never reuse a stale versioned ZIP from another commit.
    Invoke-DevStep 'release build (fresh e2e artifact)' { composer release }
    try {
        Invoke-DevStep 'runtime contract' { npm run test:e2e:runtime-contract }
        Invoke-DevStep 'smoke suite' { npm run test:e2e:smoke }
        Invoke-DevStep 'full suite' { npm run test:e2e:full }
    } finally {
        Write-Host 'e2e: runtime cleanup (playwright servers stop with the test process; removing report scratch)'
        $scratch = Join-Path $RepoRoot 'test-results'
        if (Test-Path -LiteralPath $scratch) { Remove-Item -LiteralPath $scratch -Recurse -Force }
    }
}

function Invoke-Coverage {
    Set-Location -LiteralPath $RepoRoot
    $mods = (php -m) -join "`n"
    if ($mods -notmatch '(?m)^xdebug$') {
        throw 'coverage: Xdebug not loaded. Install the matching php_xdebug.dll for your PHP (see https://xdebug.org/wizard), enable zend_extension=xdebug in php.ini, and re-run. (php -m must list xdebug.)'
    }
    Invoke-DevStep 'coverage full' { composer test:coverage:full }
}

function Get-PluginVersion {
    $line = Select-String -Path (Join-Path $RepoRoot 'readme.txt') -Pattern '^Stable tag:\s*([0-9.]+)' | Select-Object -First 1
    if (-not $line) { throw 'build: cannot determine version (readme.txt Stable tag missing)' }
    return $line.Matches[0].Groups[1].Value
}

function Invoke-Build {
    Set-Location -LiteralPath $RepoRoot
    $dirty = git -C $RepoRoot status --porcelain
    if (-not [string]::IsNullOrWhiteSpace("$dirty")) {
        Write-Host 'build: working tree is dirty:'
        Write-Host "$dirty"
        throw 'build: requires a clean source tree (commit or stash first)'
    }
    Invoke-DevStep 'vendor prefixing' { composer vendor:prefix }
    Invoke-DevStep 'composer release' { composer release }
    $version = Get-PluginVersion
    $zipPath = Join-Path $RepoRoot ("dist\sscribe-export-site-pages-{0}.zip" -f $version)
    if (-not (Test-Path -LiteralPath $zipPath)) { throw ("build: expected artifact missing: {0}" -f $zipPath) }
    $info = Get-ArtifactInfo -Zip $zipPath
    $sha = (git -C $RepoRoot rev-parse HEAD).Trim()
    Write-Host ''
    Write-Host ("VERSION   {0}" -f $version)
    Write-Host ("SOURCE SHA {0}" -f $sha)
    Write-Host ("ZIP       {0}" -f $info.Path)
    Write-Host ("SHA-256   {0}" -f $info.Hash)
    Write-Host ("BYTES     {0}" -f $info.Bytes)
    Write-Host ("ENTRIES   {0}" -f $info.Entries)
}

function Get-ArtifactInfo {
    param([Parameter(Mandatory = $true)][string]$Zip)
    $item = Get-Item -LiteralPath $Zip
    $hash = (Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256).Hash
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stream = [System.IO.File]::OpenRead($item.FullName)
    try {
        $archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Read)
        $entries = $archive.Entries.Count
        $archive.Dispose()
    } finally {
        $stream.Dispose()
    }
    return @{ Path = $item.FullName; Hash = $hash; Bytes = $item.Length; Entries = $entries }
}

function Invoke-Artifact {
    Set-Location -LiteralPath $RepoRoot
    $zipPath = $Zip
    if ([string]::IsNullOrWhiteSpace($zipPath)) {
        $cands = @(Get-ChildItem -Path (Join-Path $RepoRoot 'dist') -Filter 'sscribe-export-site-pages-*.zip' -ErrorAction SilentlyContinue)
        if ($cands.Count -ne 1) { throw 'artifact: pass -Zip explicitly (zero or multiple candidate ZIPs in dist/)' }
        $zipPath = $cands[0].FullName
    }
    if (-not (Test-Path -LiteralPath $zipPath)) { throw ("artifact: ZIP not found: {0}" -f $zipPath) }
    $info = Get-ArtifactInfo -Zip $zipPath
    Write-Host ("ZIP       {0}" -f $info.Path)
    Write-Host ("SHA-256   {0}" -f $info.Hash)
    Write-Host ("BYTES     {0}" -f $info.Bytes)
    Write-Host ("ENTRIES   {0}" -f $info.Entries)
    if ('' -ne $ExpectedHash -and $info.Hash -ne $ExpectedHash.ToUpper()) {
        throw ("artifact: SHA-256 mismatch (expected {0})" -f $ExpectedHash)
    }
    if ($ExpectedBytes -ge 0 -and $info.Bytes -ne $ExpectedBytes) {
        throw ("artifact: byte-size mismatch (expected {0})" -f $ExpectedBytes)
    }
    if ($ExpectedEntries -ge 0 -and $info.Entries -ne $ExpectedEntries) {
        throw ("artifact: entry-count mismatch (expected {0})" -f $ExpectedEntries)
    }
    Write-Host 'artifact: verification PASS'
}

switch ($Command) {
    'doctor' { Invoke-Doctor }
    'setup' { Invoke-Setup }
    'clean' { Invoke-Clean }
    'test' { Invoke-Test }
    'verify' { Invoke-Verify }
    'e2e' { Invoke-E2E }
    'coverage' { Invoke-Coverage }
    'build' { Invoke-Build }
    'artifact' { Invoke-Artifact }
    default { throw "Usage: .\scripts\dev.ps1 <doctor|setup|clean|test|verify|e2e|coverage|build|artifact>" }
}
