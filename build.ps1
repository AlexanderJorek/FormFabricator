<#
.SYNOPSIS
    Builds a clean, WordPress.org-ready copy of FormFabricator into build/formfabricator/
    and zips it to build/formfabricator.zip.

.DESCRIPTION
    Copies the plugin into build/formfabricator/, then runs `composer install
    --no-dev` INSIDE that copy only. Your working vendor/ folder (with the dev
    tools like phpcs/wpcs) is never touched. Run this before every release.

    On a clone that has no vendor/ yet, the dev dependencies are installed first
    (see -Setup); there is no separate bootstrap step to remember.

.PARAMETER SkipAudit
    Skips `composer audit`, which needs network access to the advisory database, for an offline build.
    Offline also means the test database is not downloaded: when it is not set up yet, the WordPress
    integration suite is skipped with a warning instead.

.PARAMETER Setup
    Installs/refreshes the working tree's dev dependencies (composer install) and the test database
    for the integration suite (build-testdb.ps1), and stops without building. This is the first thing
    to run on a fresh clone, and the way to catch vendor/ up after a pull that changed composer.lock.

.EXAMPLE
    ./build.ps1

.EXAMPLE
    ./build.ps1 -Setup
#>

param(
    [switch]$SkipAudit,
    [switch]$Setup
)

$ErrorActionPreference = 'Stop'

$root      = $PSScriptRoot
$buildDir  = Join-Path $root 'build'
$stageDir  = Join-Path $buildDir 'formfabricator'
$zipPath   = Join-Path $buildDir 'formfabricator.zip'

# ---- Dev environment. vendor/ is gitignored (except vendor/pdfjs and vendor/altcha), so a fresh clone has no
# phpcs: install the dev dependencies instead of failing the first gate.

# Write-Host + exit rather than throw, so the hint isn't buried under a stack trace.
function Assert-Tool {
    param([string]$Name, [string]$Hint)
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        Write-Host ""
        Write-Host "$Name is not on PATH." -ForegroundColor Red
        Write-Host "  $Hint" -ForegroundColor Red
        exit 1
    }
}

function Install-DevDependencies {
    # A CLI php.ini may lack ext-gd/ext-fileinfo, which installing doesn't need; the phpunit gate checks for them.
    Write-Host "Installing dev dependencies (composer install)..." -ForegroundColor Cyan
    Push-Location $root
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        composer install --no-interaction --ignore-platform-req=ext-gd --ignore-platform-req=ext-fileinfo
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
        Pop-Location
    }
    if ($LASTEXITCODE -ne 0) { throw "composer install failed with exit code $LASTEXITCODE" }
}

# vendor/ may be stale after a pull that changed composer.lock: compare the locked versions with installed.json
# (timestamps can't tell).
function Get-StalePackages {
    $lock      = Get-Content -Raw -LiteralPath (Join-Path $root 'composer.lock') | ConvertFrom-Json
    $installed = Get-Content -Raw -LiteralPath $installedJson | ConvertFrom-Json
    $have      = @{}
    foreach ($package in $installed.packages) { $have[$package.name] = $package.version }
    $stale = @()
    foreach ($package in @($lock.packages) + @($lock.'packages-dev')) {
        if (-not $have.ContainsKey($package.name)) {
            $stale += "$($package.name) $($package.version) is not installed"
        } elseif ($have[$package.name] -ne $package.version) {
            $stale += "$($package.name) is $($have[$package.name]), locked at $($package.version)"
        }
    }
    return $stale
}

. (Join-Path $root 'build-testdb.ps1') # the throwaway MariaDB for the integration suite

Assert-Tool 'php' 'Install PHP 8.1 or newer and put it on PATH -- the release gates run php -l, phpcs and languages/make-pot.php.'
Assert-Tool 'composer' 'Install Composer from https://getcomposer.org/download/, then open a new shell so PATH is picked up.'
Assert-Tool 'npm' 'Install Node.js 22.13 or newer (it includes npm) from https://nodejs.org/ -- the JS test suite (tests/js/) is a release gate.'

$installedJson = Join-Path $root 'vendor\composer\installed.json'
$devToolsReady = (Test-Path (Join-Path $root 'vendor\autoload.php')) -and
                 (Test-Path (Join-Path $root 'vendor\bin\phpcs.bat')) -and
                 (Test-Path $installedJson)
if ($Setup) {
    Install-DevDependencies
    if (-not (Initialize-TestDatabase)) { throw 'The test database could not be set up.' }
    Write-Host "  Test database ready in $TestDbHome" -ForegroundColor Green
    Write-Host ""
    Write-Host "Dev environment ready. Run this script again (or build.cmd) to produce a release." -ForegroundColor Green
    exit 0
}
if (-not $devToolsReady) {
    Write-Host "No dev dependencies in vendor/ yet -- a clone starts without them." -ForegroundColor Yellow
    Install-DevDependencies
    Write-Host ""
} else {
    $stalePackages = @(Get-StalePackages)
    if ($stalePackages.Count -gt 0) {
        Write-Host "vendor/ no longer matches composer.lock:" -ForegroundColor Yellow
        $stalePackages | Select-Object -First 5 | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
        Install-DevDependencies
        Write-Host ""
    }
}

# ---- Release gates (CONTRIBUTING.md), before anything is staged. Only exit codes count; a tool's output is shown
# only on failure, so each gate prints its name, then "ok"/"FAILED" and its duration. -Quiet is for looped gates.
function Invoke-ReleaseGate {
    param([string]$Name, [scriptblock]$Command, [switch]$Quiet)
    if (-not $Quiet) { Write-Host "  $Name ... " -NoNewline }
    $timer = [Diagnostics.Stopwatch]::StartNew()
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Command 2>&1
        $code   = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($code -ne 0) {
        if (-not $Quiet) { Write-Host 'FAILED' -ForegroundColor Red }
        $output | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
        throw "Release gate failed: $Name (exit code $code)"
    }
    if (-not $Quiet) { Write-Host ('ok ({0})' -f (Format-GateTime $timer.Elapsed)) -ForegroundColor Green }
}

function Format-GateTime {
    param([TimeSpan]$Elapsed)
    if ($Elapsed.TotalSeconds -lt 60) { return '{0:N0} s' -f [Math]::Max(1, $Elapsed.TotalSeconds) }
    return '{0}:{1:00} min' -f [int][Math]::Floor($Elapsed.TotalMinutes), $Elapsed.Seconds
}

Write-Host "Running release gates..." -ForegroundColor Cyan
Push-Location $root
try {
    # Matched against the path relative to the repository, never the absolute one: this repository lives under a
    # directory called .git (C:\.git\FormFabricator), so an absolute-path match excluded every file and the gate
    # checked nothing — make-pot.php prunes '.git' the same way for the same reason.
    $phpSources = Get-ChildItem -Path $root -Recurse -File -Filter '*.php' | Where-Object {
        $_.FullName.Substring($root.Length + 1) -notmatch '(^|[\\/])(vendor|build|node_modules|\.git)[\\/]'
    }
    Write-Host "  php -l ($(@($phpSources).Count) files) ... " -NoNewline
    $lintTimer = [Diagnostics.Stopwatch]::StartNew()
    try {
        foreach ($phpSource in $phpSources) {
            Invoke-ReleaseGate "php -l $($phpSource.FullName.Substring($root.Length + 1))" { php -l $phpSource.FullName } -Quiet
        }
    } catch {
        Write-Host 'FAILED' -ForegroundColor Red
        throw
    }
    Write-Host ('ok ({0})' -f (Format-GateTime $lintTimer.Elapsed)) -ForegroundColor Green
    # .phpcs.xml (picked up from the working directory) is the day-to-day PSR gate; errors fail, warnings don't.
    Invoke-ReleaseGate 'phpcs (.phpcs.xml)' { & (Join-Path $root 'vendor\bin\phpcs.bat') -q --runtime-set ignore_warnings_on_exit 1 }
    # .phpcs-security.xml: its ERRORS fail the release, as WordPress.org's Plugin Check rejects the same ones. Warnings
    # (-n hides them) stay review material, since the security-audit heuristics flag any dynamic filesystem call.
    $securityRuleset = Join-Path $root '.phpcs-security.xml'
    Invoke-ReleaseGate 'phpcs (.phpcs-security.xml, errors only)' { & (Join-Path $root 'vendor\bin\phpcs.bat') -q -n "--standard=$securityRuleset" }
    Invoke-ReleaseGate 'make-pot --check (languages/formfabricator.pot is current)' { php (Join-Path $root 'languages\make-pot.php') --check }
    # The unit and perf suites (tests/, phpunit.xml.dist). Perf holds the pathological-PDF shapes from CONTRIBUTING.md
    # ("Scanning untrusted PDF bytes"), so a scan that turns quadratic stops the release. The integration suite follows
    # after the JS suite below.
    $missingExtensions = @('gd', 'fileinfo') | Where-Object { -not ((php -r "echo extension_loaded('$_') ? 1 : 0;") -eq '1') }
    if ($missingExtensions.Count -gt 0) {
        throw "Release gate failed: the tests need PHP's $($missingExtensions -join ' and ') extension(s), which every WordPress host has. Enable them in $((php -r 'echo php_ini_loaded_file();'))."
    }
    Invoke-ReleaseGate 'phpunit (unit + perf suites)' { & (Join-Path $root 'vendor\bin\phpunit.bat') --testsuite unit,perf --no-progress }
    # The JS suite (tests/js/): the real front.js in jsdom, against the globals Assets hands it, and checked case by case
    # against the server's condition logic. npm ci installs exactly what package-lock.json pins (the counterpart of the
    # composer.lock check above); --prefer-offline keeps a build working from npm's cache without the network.
    Invoke-ReleaseGate 'npm ci (JS test dependencies)' { npm ci --prefer-offline --no-audit --no-fund }
    Invoke-ReleaseGate 'npm test (JS suite)' { npm test }
    # The WordPress integration suite (tests/Integration, phpunit-integration.xml.dist): real WordPress from vendor/
    # against a throwaway MariaDB that exists only for this step (build-testdb.ps1), once as a single site and once as a
    # multisite network. Offline, without a database set up yet, it is skipped rather than downloaded.
    if (-not (Initialize-TestDatabase -Offline:$SkipAudit)) {
        Write-Host "  integration suite SKIPPED: no test database yet, and -SkipAudit (offline) does not download one." -ForegroundColor Yellow
        Write-Host "  Run a full build or build.ps1 -Setup once to set it up." -ForegroundColor Yellow
    } else {
        $testDbEnv = @('WP_TESTS_DB_HOST', 'WP_TESTS_DB_NAME', 'WP_TESTS_DB_USER', 'WP_TESTS_DB_PASSWORD', 'WP_MULTISITE', 'PHP_INI_SCAN_DIR')
        $savedEnv  = @{}
        foreach ($name in $testDbEnv) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
        $testDb = Start-TestDatabase -IniDir (Get-MysqliIniDir)
        try {
            $env:WP_TESTS_DB_HOST     = "127.0.0.1:$($testDb.Port)"
            $env:WP_TESTS_DB_NAME     = 'wordpress_test'
            $env:WP_TESTS_DB_USER     = 'root'
            $env:WP_TESTS_DB_PASSWORD = $TestDbRootPw
            # Through PHP_INI_SCAN_DIR, not -d: WordPress installs the test site in a child PHP process, which needs it too.
            if ($testDb.IniDir) { $env:PHP_INI_SCAN_DIR = $testDb.IniDir }
            $integrationConfig = Join-Path $root 'phpunit-integration.xml.dist'
            $env:WP_MULTISITE = '0'
            Invoke-ReleaseGate 'phpunit integration (single site)' { & (Join-Path $root 'vendor\bin\phpunit.bat') -c $integrationConfig --no-progress }
            $env:WP_MULTISITE = '1'
            Invoke-ReleaseGate 'phpunit integration (multisite)' { & (Join-Path $root 'vendor\bin\phpunit.bat') -c $integrationConfig --no-progress }
        } finally {
            foreach ($name in $testDbEnv) { [Environment]::SetEnvironmentVariable($name, $savedEnv[$name], 'Process') }
            Stop-TestDatabase $testDb
        }
    }
    if ($SkipAudit) {
        Write-Host "  composer audit skipped (-SkipAudit)" -ForegroundColor Yellow
    } else {
        # --no-dev: the gate is about what ships. Dev tooling advisories still show up in a plain `composer audit`.
        Invoke-ReleaseGate 'composer audit (known vulnerabilities in shipped packages)' { composer audit --locked --no-dev --no-interaction }
    }
} finally {
    Pop-Location
}

Write-Host "Cleaning previous build..." -ForegroundColor Cyan
if (Test-Path $buildDir) { Remove-Item -Recurse -Force $buildDir }
New-Item -ItemType Directory -Path $stageDir | Out-Null

Write-Host "Copying plugin files..." -ForegroundColor Cyan
$exclude = @('.git', '.claude', '.vscode', '.gitignore', '.gitattributes', '.github', 'build', 'node_modules', 'vendor', '.phpcs.xml', '.phpcs-security.xml', 'build.ps1', 'build-testdb.ps1', 'build.cmd', 'CLAUDE.md', 'CONTRIBUTING.md', 'TESTING.md', 'tests', 'phpunit.xml.dist', 'phpunit-integration.xml.dist', '.phpunit.cache', 'package.json', 'package-lock.json')
Get-ChildItem -Path $root -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stageDir -Recurse -Force
}

# Dev-only files inside shipped folders. The example field is a template that is never loaded, and WordPress.org asks
# plugins not to ship unreachable code.
$nestedExclude = @(
    'includes/PDF/templates/HEADER-RENDERING.md',
    'languages/compile-mo.php',
    'languages/make-pot.php',
    'includes/Fields/_ExampleField.php',
    'assets/js/fabricator-perf-debug.js',
    'assets/js/fields/ExampleField.clientInitClickHandler.js',
    'assets/js/fields/ExampleField.clientValidationZip.js',
    'assets/css/fields/ExampleField.stylesComposite.css'
)
foreach ($rel in $nestedExclude) {
    $path = Join-Path $stageDir $rel
    if (Test-Path $path) { Remove-Item -Force $path }
}

# WordPress.org manages translations via translate.wordpress.org — .po/.mo files must not ship
# in the plugin package (the .pot source template is kept; it carries no translated content).
Get-ChildItem -Path (Join-Path $stageDir 'languages') -Include '*.po', '*.mo' -Recurse -Force -ErrorAction SilentlyContinue |
    Remove-Item -Force

Write-Host "Installing production-only dependencies (composer install --no-dev)..." -ForegroundColor Cyan
Push-Location $stageDir
try {
    # Composer writes progress to stderr, which 'Stop' would turn into an abort; $LASTEXITCODE decides instead.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        # A local CLI may lack ext-gd/ext-fileinfo; WordPress hosts have them.
        composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-gd --ignore-platform-req=ext-fileinfo
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($LASTEXITCODE -ne 0) { throw "composer install failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

# The hand-vendored npm packages aren't Composer's, so they are copied in, each checked first against the SHA-256 list
# in its VERSION file: any edited, missing or extra file stops the build.
$handVendored = @(
    @{ Dir = 'pdfjs';  Npm = 'pdfjs-dist'; License = 'Apache-2.0' },
    @{ Dir = 'altcha'; Npm = 'altcha';     License = 'MIT' }
)
foreach ($hv in $handVendored) {
    Write-Host "Verifying manually-vendored $($hv.Npm) against its pinned hashes..." -ForegroundColor Cyan
    $hvDir    = (Resolve-Path (Join-Path $root ('vendor\' + $hv.Dir))).ProviderPath
    $pinned   = @{}
    foreach ($pin in [regex]::Matches((Get-Content -Raw -Path (Join-Path $hvDir 'VERSION')), '(?m)^\s*([0-9a-fA-F]{64})\s+(\S+)\s*$')) {
        $pinned[$pin.Groups[2].Value] = $pin.Groups[1].Value.ToLowerInvariant()
    }
    $problems = @()
    foreach ($file in Get-ChildItem -Path $hvDir -Recurse -File -Force | Where-Object { $_.Name -ne 'VERSION' }) {
        $rel = $file.FullName.Substring($hvDir.TrimEnd('\').Length + 1).Replace('\', '/')
        if (-not $pinned.ContainsKey($rel)) {
            $problems += "$rel has no pinned hash"
            continue
        }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
        if ($actual -ne $pinned[$rel]) {
            $problems += "$rel is $actual, pinned $($pinned[$rel])"
        }
    }
    foreach ($rel in $pinned.Keys) {
        if (-not (Test-Path -LiteralPath (Join-Path $hvDir $rel) -PathType Leaf)) {
            $problems += "$rel is pinned but missing"
        }
    }
    if ($problems.Count -gt 0 -or $pinned.Count -eq 0) {
        throw "vendor/$($hv.Dir) does not match the SHA-256 list in its VERSION file: $(if ($pinned.Count -eq 0) { 'no hashes pinned' } else { $problems -join '; ' })"
    }
    $hv.Pinned = $pinned
    Write-Host "Copying manually-vendored $($hv.Npm)..." -ForegroundColor Cyan
    Copy-Item -Path $hvDir -Destination (Join-Path $stageDir ('vendor\' + $hv.Dir)) -Recurse -Force
}

# mPDF ships ~88 MB of fonts; the PDF selects only four families (layout.php's font_family match). Their Condensed
# variants stay too: mPDF's font-alias tables (Config/FontVariables.php) reach them when resolving a font by role.
# Only the staged copy is trimmed. A new selectable PDF font must be added to $keepFonts, or PDFs fatal on
# "font file not found".
Write-Host "Trimming unused mPDF font files..." -ForegroundColor Cyan
$fontsDir = Join-Path $stageDir 'vendor\mpdf\mpdf\ttfonts'
$keepFonts = @(
    'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans-Oblique.ttf', 'DejaVuSans-BoldOblique.ttf',
    'DejaVuSansCondensed.ttf', 'DejaVuSansCondensed-Bold.ttf', 'DejaVuSansCondensed-Oblique.ttf', 'DejaVuSansCondensed-BoldOblique.ttf',
    'DejaVuSerif.ttf', 'DejaVuSerif-Bold.ttf', 'DejaVuSerif-Italic.ttf', 'DejaVuSerif-BoldItalic.ttf',
    'DejaVuSerifCondensed.ttf', 'DejaVuSerifCondensed-Bold.ttf', 'DejaVuSerifCondensed-Italic.ttf', 'DejaVuSerifCondensed-BoldItalic.ttf',
    'DejaVuSansMono.ttf', 'DejaVuSansMono-Bold.ttf', 'DejaVuSansMono-Oblique.ttf', 'DejaVuSansMono-BoldOblique.ttf',
    'FreeMono.ttf', 'FreeMonoBold.ttf', 'FreeMonoOblique.ttf', 'FreeMonoBoldOblique.ttf',
    'DejaVuinfo.txt', 'GNUFreeFontinfo.txt'
)
if (-not (Test-Path $fontsDir)) {
    # Fail here, where the reason is known, rather than at the size ceiling later.
    throw "mPDF font directory not found at $fontsDir - the trim would silently ship the full font set."
}
$before = (Get-ChildItem -Path $fontsDir -File | Measure-Object -Property Length -Sum).Sum
Get-ChildItem -Path $fontsDir -File | Where-Object { $keepFonts -notcontains $_.Name } | Remove-Item -Force
$after = (Get-ChildItem -Path $fontsDir -File | Measure-Object -Property Length -Sum).Sum
# Every kept font must still be there.
$missingFonts = @($keepFonts | Where-Object { -not (Test-Path (Join-Path $fontsDir $_)) })
if ($missingFonts.Count -gt 0) {
    throw "fonts named in `$keepFonts are absent after the trim (upstream renamed them?): $($missingFonts -join ', ')"
}
Write-Host ("  {0:N1} MB -> {1:N1} MB" -f ($before / 1MB), ($after / 1MB)) -ForegroundColor Cyan

# Dev-only tooling that dependencies bundle in their own dist (--no-dev can't strip it): CI and analysis configs, an
# unused mPDF tmp/ (Generator always passes a tempDir), and a shell script Plugin Check rejects.
Write-Host "Removing dev-only tooling bundled inside dependencies..." -ForegroundColor Cyan
$vendorExclude = @(
    'vendor/paragonie/random_compat/build-phar.sh',
    'vendor/paragonie/random_compat/dist',
    'vendor/paragonie/random_compat/other',
    'vendor/paragonie/random_compat/psalm-autoload.php',
    'vendor/paragonie/random_compat/psalm.xml',
    'vendor/mpdf/mpdf/.github',
    'vendor/mpdf/mpdf/.gitignore',
    'vendor/mpdf/mpdf/tmp',
    'vendor/mpdf/mpdf/phpstan-baseline.neon',
    'vendor/mpdf/mpdf/phpstan.neon',
    'vendor/mpdf/mpdf/phpunit.xml',
    'vendor/mpdf/mpdf/ruleset.xml',
    'vendor/mpdf/psr-log-aware-trait/.gitignore'
)
foreach ($rel in $vendorExclude) {
    $path = Join-Path $stageDir $rel
    if (Test-Path $path) { Remove-Item -Recurse -Force $path }
}

# Hidden files in vendor/, which Plugin Check rejects, deepest first. In the plugin's own tree they fail verification.
Get-ChildItem -Path (Join-Path $stageDir 'vendor') -Recurse -Force |
    Where-Object { $_.Name -like '.*' } |
    Sort-Object { $_.FullName.Length } -Descending |
    ForEach-Object { if (Test-Path -LiteralPath $_.FullName) { Remove-Item -LiteralPath $_.FullName -Recurse -Force } }

Write-Host "Creating zip..." -ForegroundColor Cyan
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }

# Not Compress-Archive: under Windows PowerShell 5.1 it writes backslash separators (ZIP APPNOTE 4.4.17.1 requires
# '/'), which non-Windows hosts unzip as flat files.
Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

$sep       = [string][char]92
$prefixLen = (Split-Path $stageDir -Parent).Length + 1
$zip       = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $stageDir -Recurse -Force -File | ForEach-Object {
        $entryName = $_.FullName.Substring($prefixLen).Replace($sep, '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
    # Directory entries only where a directory would otherwise vanish; unzip_file()
    # recreates every other directory from the file paths.
    Get-ChildItem -Path $stageDir -Recurse -Force -Directory | Where-Object {
        -not (Get-ChildItem -Path $_.FullName -Recurse -Force -File | Select-Object -First 1)
    } | ForEach-Object {
        $zip.CreateEntry($_.FullName.Substring($prefixLen).Replace($sep, '/') + '/') | Out-Null
    }
} finally {
    $zip.Dispose()
}

# ---- Verify the artifact itself: the exclusion lists match exact names, so anything new slips past them.
Write-Host "Verifying archive..." -ForegroundColor Cyan
$violations = @()

$verify = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $entryNames = @($verify.Entries | ForEach-Object { $_.FullName })
} finally {
    $verify.Dispose()
}

$badSep = @($entryNames | Where-Object { $_.Contains($sep) })
if ($badSep.Count -gt 0) {
    $violations += "$($badSep.Count) archive entries use a backslash separator (ZIP APPNOTE 4.4.17.1 requires '/'); first: $($badSep[0])"
}

$roots = @($entryNames | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
if ($roots.Count -ne 1 -or $roots[0] -ne 'formfabricator') {
    $violations += "archive must contain exactly one top-level folder 'formfabricator'; found: $($roots -join ', ')"
}

# Relative paths, normalized to '/', so nothing below has to reason about separators.
$stagedFiles = @()
Get-ChildItem -Path $stageDir -Recurse -Force -File | ForEach-Object {
    $stagedFiles += [PSCustomObject]@{
        Rel    = $_.FullName.Substring($stageDir.Length + 1).Replace($sep, '/')
        Length = $_.Length
    }
}
$ownFiles = @($stagedFiles | Where-Object { $_.Rel -notlike 'vendor/*' })

# Every staged file in the archive under its own name, and nothing else (a skipped locked file would pass a count).
$expected = @{}
foreach ($file in $stagedFiles) { $expected['formfabricator/' + $file.Rel] = $true }
Get-ChildItem -Path $stageDir -Recurse -Force -Directory | Where-Object {
    -not (Get-ChildItem -Path $_.FullName -Recurse -Force -File | Select-Object -First 1)
} | ForEach-Object {
    $expected['formfabricator/' + $_.FullName.Substring($stageDir.Length + 1).Replace($sep, '/') + '/'] = $true
}
$actual = @{}
foreach ($name in $entryNames) { $actual[$name] = $true }
$absent = @($expected.Keys | Where-Object { -not $actual.ContainsKey($_) })
$unexpected = @($entryNames | Where-Object { -not $expected.ContainsKey($_) })
if ($absent.Count -gt 0) {
    $violations += "$($absent.Count) staged file(s) missing from the archive; first: $($absent[0])"
}
if ($unexpected.Count -gt 0) {
    $violations += "$($unexpected.Count) archive entr(ies) match no staged file; first: $($unexpected[0])"
}

# Dev/test material anywhere in the plugin's own tree; vendor/ is upstream's business and
# is pruned separately above.
$devPattern = '(?i)(^|/)(tests?|testpdfs|fixtures|__tests__|[.]github|node_modules|[.]git|[.]vscode|[.]claude|phpunit(-[a-z]+)?[.]xml([.]dist)?|[.]phpunit[.]cache|package(-lock)?[.]json)(/|$)'
$devLeaks   = @($ownFiles | Where-Object { $_.Rel -match $devPattern })
if ($devLeaks.Count -gt 0) {
    $violations += "dev/test material in package: $(($devLeaks | Select-Object -First 5 | ForEach-Object { $_.Rel }) -join ', ')"
}

# Hidden files, OS and editor debris, logs and backups, by pattern anywhere in the package.
$junkPattern = '(?i)(^|/)(\.[^/]+|thumbs\.db|desktop\.ini|__macosx)(/|$)|\.(log|env|bak|swp|swo|tmp|orig|rej)$'
$junk        = @($stagedFiles | Where-Object { $_.Rel -match $junkPattern })
if ($junk.Count -gt 0) {
    $violations += "hidden, OS, editor, log or backup files in package: $(($junk | Select-Object -First 5 | ForEach-Object { $_.Rel }) -join ', ')"
}

# Executables and scripts, which Plugin Check rejects, by pattern over the finished tree.
$scriptPattern = '(?i)\.(ps1|psm1|psd1|bat|cmd|sh|bash|exe|com|msi|scr|vbs)$'
$scriptLeaks   = @($stagedFiles | Where-Object { $_.Rel -match $scriptPattern })
if ($scriptLeaks.Count -gt 0) {
    $violations += "executable or shell-script files in package: $(($scriptLeaks | Select-Object -First 5 | ForEach-Object { $_.Rel }) -join ', ')"
}

# The nested and translation exclusion lists are only believable if their targets are gone.
foreach ($rel in $nestedExclude) {
    if (Test-Path (Join-Path $stageDir $rel)) { $violations += "excluded file still present: $rel" }
}
$leftoverTranslations = @(Get-ChildItem -Path (Join-Path $stageDir 'languages') -Include '*.po', '*.mo' -Recurse -Force -ErrorAction SilentlyContinue)
if ($leftoverTranslations.Count -gt 0) {
    $violations += "$($leftoverTranslations.Count) .po/.mo files still in languages/ (translate.wordpress.org owns these)"
}

# The hand-vendored packages, which no dependency step would miss, against the same pinned lists.
foreach ($hv in $handVendored) {
    foreach ($rel in $hv.Pinned.Keys) {
        $stagedFile = Join-Path (Join-Path $stageDir ('vendor\' + $hv.Dir)) $rel
        if (-not (Test-Path -LiteralPath $stagedFile -PathType Leaf)) {
            $violations += "vendor/$($hv.Dir)/$rel is missing from the package"
        } elseif ((Get-FileHash -Algorithm SHA256 -LiteralPath $stagedFile).Hash.ToLowerInvariant() -ne $hv.Pinned[$rel]) {
            $violations += "vendor/$($hv.Dir)/$rel in the package differs from its pinned SHA-256"
        }
    }
}

# Size ceilings catch what nobody predicted: one for the plugin's own tree, one for the whole package (vendor/ is most
# of it).
$ownBytes   = ($ownFiles | Measure-Object -Property Length -Sum).Sum
$totalBytes = ($stagedFiles | Measure-Object -Property Length -Sum).Sum
$zipBytes   = (Get-Item $zipPath).Length
if ($ownBytes -gt 12MB) {
    $violations += "plugin tree excluding vendor/ is $([math]::Round($ownBytes / 1MB, 1)) MB, over the 12 MB ceiling -- something large is shipping that probably should not be"
}
if ($totalBytes -gt 40MB) {
    $violations += "staged package is $([math]::Round($totalBytes / 1MB, 1)) MB, over the 40 MB ceiling -- check that the mPDF font trim actually ran"
}

if ($violations.Count -gt 0) {
    # A rejected archive never stays where a good one would be.
    Remove-Item -Force $zipPath -ErrorAction SilentlyContinue
    Write-Host ""
    Write-Host "Build REJECTED -- this package is not shippable:" -ForegroundColor Red
    $violations | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    Write-Host "  (the archive has been deleted; $stageDir is left in place for inspection)" -ForegroundColor Red
    throw "Package verification failed ($($violations.Count) problem(s))."
}

# Not fatal: WordPress.org's plugin submission form has historically capped uploads around 10 MB.
# Worth knowing before the upload rather than during it, but not worth failing a build over a
# limit this script cannot verify.
if ($zipBytes -gt 9MB) {
    Write-Host ("  NOTE: archive is {0:N1} MB, close to the ~10 MB WordPress.org submission limit" -f ($zipBytes / 1MB)) -ForegroundColor Yellow
}

Write-Host ("  {0} entries, all reconciled against {1} staged files, forward-slash separators, single 'formfabricator/' root" -f $entryNames.Count, $stagedFiles.Count) -ForegroundColor DarkGray
Write-Host ("  {0} MB staged ({1} MB excluding vendor/), archive {2} MB" -f [math]::Round($totalBytes / 1MB, 1), [math]::Round($ownBytes / 1MB, 1), [math]::Round($zipBytes / 1MB, 1)) -ForegroundColor DarkGray

# ---- Software bill of materials (NIST SSDF PS.3): CycloneDX 1.5 JSON next to the zip, never inside it. Lists what the
# package ships: every Composer package in composer.lock's production set (what `install --no-dev` put into the staged
# vendor/), plus the two hand-vendored front-end libraries.
Write-Host "Writing SBOM..." -ForegroundColor Cyan
$sbomPath      = Join-Path $buildDir 'formfabricator-sbom.cdx.json'
$lock          = Get-Content -Raw -Path (Join-Path $root 'composer.lock') | ConvertFrom-Json
$pluginVersion = ([regex]::Match((Get-Content -Raw -Path (Join-Path $root 'formfabricator.php')), 'Version:\s*([0-9.]+)')).Groups[1].Value
$components    = New-Object System.Collections.ArrayList
foreach ($package in $lock.packages) {
    $component = [ordered]@{
        type     = 'library'
        name     = $package.name
        version  = $package.version
        purl     = "pkg:composer/$($package.name)@$($package.version)"
        licenses = @($package.license | ForEach-Object { [ordered]@{ license = [ordered]@{ id = $_ } } })
    }
    if ($package.dist -and $package.dist.url) {
        $component['externalReferences'] = @([ordered]@{ type = 'distribution'; url = $package.dist.url; comment = "reference $($package.dist.reference)" })
    }
    [void]$components.Add($component)
}
foreach ($hv in $handVendored) {
    $hvVersionText = Get-Content -Raw -Path (Join-Path $root ('vendor\' + $hv.Dir + '\VERSION'))
    $hvVersion     = ([regex]::Match($hvVersionText, '(?m)^' + [regex]::Escape($hv.Npm) + '\s+([0-9.]+)')).Groups[1].Value
    $hvComponent   = [ordered]@{
        type     = 'library'
        name     = $hv.Npm
        version  = $hvVersion
        purl     = "pkg:npm/$($hv.Npm)@$hvVersion"
        licenses = @([ordered]@{ license = [ordered]@{ id = $hv.License } })
    }
    # The npm tarball's integrity hash, recorded in VERSION; the shipped files were checked against its SHA-256 list above.
    $hvIntegrity = [regex]::Match($hvVersionText, 'Tarball integrity:\s*sha512-([A-Za-z0-9+/=]+)').Groups[1].Value
    if ($hvIntegrity -ne '') {
        $hvComponent['externalReferences'] = @([ordered]@{
            type   = 'distribution'
            url    = "https://registry.npmjs.org/$($hv.Npm)/-/$($hv.Npm)-$hvVersion.tgz"
            hashes = @([ordered]@{ alg = 'SHA-512'; content = ([BitConverter]::ToString([Convert]::FromBase64String($hvIntegrity)) -replace '-', '').ToLowerInvariant() })
        })
    }
    [void]$components.Add($hvComponent)
}
$faVersion = ([regex]::Match((Get-Content -Raw -Path (Join-Path $root 'assets\vendor\fontawesome\css\all.min.css')), 'Font Awesome Free ([0-9.]+)')).Groups[1].Value
[void]$components.Add([ordered]@{
    type     = 'library'
    name     = '@fortawesome/fontawesome-free'
    version  = $faVersion
    purl     = "pkg:npm/%40fortawesome/fontawesome-free@$faVersion"
    licenses = @(
        [ordered]@{ license = [ordered]@{ id = 'CC-BY-4.0' } },
        [ordered]@{ license = [ordered]@{ id = 'OFL-1.1' } },
        [ordered]@{ license = [ordered]@{ id = 'MIT' } }
    )
})
$sbom = [ordered]@{
    bomFormat    = 'CycloneDX'
    specVersion  = '1.5'
    serialNumber = 'urn:uuid:' + [guid]::NewGuid().ToString()
    version      = 1
    metadata     = [ordered]@{
        timestamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        component = [ordered]@{
            type     = 'application'
            name     = 'formfabricator'
            version  = $pluginVersion
            licenses = @([ordered]@{ license = [ordered]@{ id = 'GPL-3.0-or-later' } })
        }
    }
    components   = $components
}
# BOM-less UTF-8: Windows PowerShell 5.1's Set-Content -Encoding utf8 writes a BOM that strict JSON parsers reject.
[System.IO.File]::WriteAllText($sbomPath, ($sbom | ConvertTo-Json -Depth 10), (New-Object System.Text.UTF8Encoding $false))

Write-Host ""
Write-Host "Done. Release build: $zipPath" -ForegroundColor Green
Write-Host "SBOM (not shipped): $sbomPath" -ForegroundColor Green
Write-Host "Unpacked copy for inspection: $stageDir" -ForegroundColor Green

# Explicit, so a caller (build.cmd, CI) reads success as 0 rather than inheriting whatever
# exit code the last native tool in this script happened to leave behind.
exit 0
